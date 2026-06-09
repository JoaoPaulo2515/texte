<?php
// escola/professor/relatorios/gerar_excel_estatistica_disciplina.php - Gerar Excel da Estatística por Disciplina

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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// ============================================
// PARÂMETROS
// ============================================
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

if ($disciplina_id <= 0) {
    die('Parâmetros inválidos. Disciplina não selecionada.');
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
// BUSCAR DADOS DA DISCIPLINA
// ============================================
$sql_disciplina = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

if (!$disciplina) {
    die('Disciplina não encontrada.');
}

// ============================================
// BUSCAR TURMAS ONDE O PROFESSOR LECIONA ESTA DISCIPLINA
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala,
        (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') as total_alunos
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id AND pdt.disciplina_id = :disciplina_id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id, ':disciplina_id' => $disciplina_id]);
$turmas_disciplina = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ESTATÍSTICAS POR TURMA
// ============================================
$estatisticas = [];
$total_alunos_geral = 0;
$total_aprovados_geral = 0;
$media_geral_soma = 0;
$count_turmas_com_media = 0;

foreach ($turmas_disciplina as $turma) {
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
        AND n.estudante_id IN (SELECT estudante_id FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa')
    ";
    $stmt_stats = $conn->prepare($sql_stats);
    $stmt_stats->execute([
        ':disciplina_id' => $disciplina_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id,
        ':turma_id' => $turma['id']
    ]);
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    $media = round($stats['media_geral'] ?? 0, 1);
    if ($media > 0) {
        $media_geral_soma += $media;
        $count_turmas_com_media++;
    }
    $total_aprovados_geral += $stats['total_aprovados'] ?? 0;
    $total_alunos_geral += $turma['total_alunos'];
    
    $estatisticas[] = [
        'turma_nome' => $turma['nome'],
        'turma_ano' => $turma['ano'],
        'turma_turno' => $turma['turno'],
        'turma_sala' => $turma['sala'],
        'total_alunos' => $turma['total_alunos'],
        'com_nota' => $stats['total_alunos_com_nota'] ?? 0,
        'media_geral' => $media,
        'maior_nota' => round($stats['maior_nota'] ?? 0, 1),
        'menor_nota' => round($stats['menor_nota'] ?? 0, 1),
        'aprovados' => $stats['total_aprovados'] ?? 0,
        'recuperacao' => $stats['total_recuperacao'] ?? 0,
        'reprovados' => $stats['total_reprovados'] ?? 0,
        'percentual_aprovacao' => ($stats['total_alunos_com_nota'] ?? 0) > 0 ? round(($stats['total_aprovados'] / $stats['total_alunos_com_nota']) * 100, 1) : 0,
        'percentual_com_nota' => $turma['total_alunos'] > 0 ? round(($stats['total_alunos_com_nota'] / $turma['total_alunos']) * 100, 1) : 0
    ];
}

// ============================================
// CALCULAR MÉDIAS GERAIS
// ============================================
$disciplina_media_geral = $count_turmas_com_media > 0 ? round($media_geral_soma / $count_turmas_com_media, 1) : 0;
$disciplina_taxa_aprovacao = $total_alunos_geral > 0 ? round(($total_aprovados_geral / $total_alunos_geral) * 100, 1) : 0;

// Encontrar melhor e pior turma
$melhor_turma = '';
$melhor_media = 0;
$pior_turma = '';
$pior_media = 100;

foreach ($estatisticas as $est) {
    if ($est['media_geral'] > $melhor_media) {
        $melhor_media = $est['media_geral'];
        $melhor_turma = $est['turma_ano'] . 'ª ' . $est['turma_nome'];
    }
    if ($est['media_geral'] < $pior_media && $est['media_geral'] > 0) {
        $pior_media = $est['media_geral'];
        $pior_turma = $est['turma_ano'] . 'ª ' . $est['turma_nome'];
    }
}

// ============================================
// CRIAR PLANILHA EXCEL
// ============================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Estatística por Disciplina');

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

$sheet->setCellValue('A2', 'ESTATÍSTICA POR DISCIPLINA');
$sheet->mergeCells('A2:L2');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A3', htmlspecialchars($disciplina['nome']) . ' | ' . $bimestre . 'º BIMESTRE | ANO LETIVO ' . $ano_letivo_ano);
$sheet->mergeCells('A3:L3');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A3')->getFont()->setSize(9);

// ============================================
// INFORMAÇÕES DA DISCIPLINA
// ============================================
$sheet->setCellValue('A5', 'INFORMAÇÕES DA DISCIPLINA');
$sheet->mergeCells('A5:L5');
$sheet->getStyle('A5')->getFont()->setBold(true);
$sheet->getStyle('A5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A5')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A6', 'Disciplina:');
$sheet->setCellValue('B6', htmlspecialchars($disciplina['nome']));
$sheet->setCellValue('D6', 'Código:');
$sheet->setCellValue('E6', htmlspecialchars($disciplina['codigo'] ?? '-'));
$sheet->setCellValue('G6', 'Professor:');
$sheet->setCellValue('H6', htmlspecialchars($professor['professor_nome']));
$sheet->setCellValue('J6', 'Ano Letivo:');
$sheet->setCellValue('K6', $ano_letivo_ano);

$sheet->setCellValue('A7', 'Data Emissão:');
$sheet->setCellValue('B7', date('d/m/Y H:i:s'));

$sheet->getStyle('A6:K7')->getFont()->setSize(9);
$sheet->getStyle('A6')->getFont()->setBold(true);
$sheet->getStyle('D6')->getFont()->setBold(true);
$sheet->getStyle('G6')->getFont()->setBold(true);
$sheet->getStyle('J6')->getFont()->setBold(true);
$sheet->getStyle('A7')->getFont()->setBold(true);

// ============================================
// ESTATÍSTICAS GERAIS
// ============================================
$sheet->setCellValue('A9', 'ESTATÍSTICAS GERAIS');
$sheet->mergeCells('A9:L9');
$sheet->getStyle('A9')->getFont()->setBold(true);
$sheet->getStyle('A9')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A9')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A10', 'Total de Turmas:');
$sheet->setCellValue('B10', count($turmas_disciplina));
$sheet->setCellValue('D10', 'Média Geral:');
$sheet->setCellValue('E10', $disciplina_media_geral);
$sheet->setCellValue('G10', 'Taxa de Aprovação:');
$sheet->setCellValue('H10', $disciplina_taxa_aprovacao . '%');
$sheet->setCellValue('J10', 'Total de Alunos:');
$sheet->setCellValue('K10', $total_alunos_geral);

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

$sheet->setCellValue('A13', '🏆 Melhor Turma:');
$sheet->setCellValue('B13', $melhor_turma ?: '-');
$sheet->setCellValue('D13', 'Média:');
$sheet->setCellValue('E13', $melhor_media > 0 ? $melhor_media : '-');
$sheet->setCellValue('G13', '⚠️ Turma com Menor Média:');
$sheet->setCellValue('H13', $pior_turma ?: '-');
$sheet->setCellValue('J13', 'Média:');
$sheet->setCellValue('K13', $pior_media < 100 ? $pior_media : '-');

$sheet->getStyle('A13:K13')->getFont()->setSize(9);
$sheet->getStyle('A13')->getFont()->setBold(true);
$sheet->getStyle('D13')->getFont()->setBold(true);
$sheet->getStyle('G13')->getFont()->setBold(true);
$sheet->getStyle('J13')->getFont()->setBold(true);

// ============================================
// TABELA DE ESTATÍSTICAS POR TURMA
// ============================================
$row = 15;

$sheet->setCellValue('A' . $row, 'ESTATÍSTICAS POR TURMA');
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
// Cabeçalho
$headers = ['Turma', 'Turno', 'Sala', 'Alunos', 'C/ Nota', 'Média', 'Mín.', 'Máx.', 'Aprov', 'Recup', 'Reprov', 'Aprovação'];
$colunas = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];

foreach ($headers as $index => $header) {
    $sheet->setCellValue($colunas[$index] . $row, $header);
    $sheet->getStyle($colunas[$index] . $row)->getFont()->setBold(true);
    $sheet->getStyle($colunas[$index] . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
    $sheet->getStyle($colunas[$index] . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($colunas[$index] . $row)->getFont()->setSize(9);
}

$row++;
if (empty($estatisticas)) {
    $sheet->setCellValue('A' . $row, 'Nenhuma estatística disponível para esta disciplina.');
    $sheet->mergeCells('A' . $row . ':L' . $row);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
} else {
    foreach ($estatisticas as $est) {
        $sheet->setCellValue('A' . $row, $est['turma_ano'] . 'ª ' . $est['turma_nome']);
        $sheet->setCellValue('B' . $row, ucfirst($est['turma_turno']));
        $sheet->setCellValue('C' . $row, $est['turma_sala'] ?: '-');
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
// RESUMO DA DISCIPLINA
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'RESUMO DA DISCIPLINA');
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$sheet->setCellValue('A' . $row, '• Total de Turmas:');
$sheet->setCellValue('B' . $row, count($turmas_disciplina));
$sheet->setCellValue('D' . $row, '• Total de Alunos:');
$sheet->setCellValue('E' . $row, $total_alunos_geral);
$sheet->setCellValue('G' . $row, '• Média Geral:');
$sheet->setCellValue('H' . $row, $disciplina_media_geral);
$sheet->setCellValue('J' . $row, '• Taxa de Aprovação:');
$sheet->setCellValue('K' . $row, $disciplina_taxa_aprovacao . '%');

$row++;
if ($melhor_turma) {
    $sheet->setCellValue('A' . $row, '• Melhor Desempenho:');
    $sheet->setCellValue('B' . $row, $melhor_turma . ' (Média: ' . $melhor_media . ')');
    $row++;
    $sheet->setCellValue('A' . $row, '• Turma com Maior Dificuldade:');
    $sheet->setCellValue('B' . $row, $pior_turma . ' (Média: ' . $pior_media . ')');
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
// LEGENDA
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'LEGENDA');
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$sheet->setCellValue('A' . $row, 'Cores das Notas:');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->setCellValue('B' . $row, 'Verde');
$sheet->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
$sheet->setCellValue('C' . $row, 'Média ≥ 9.5');
$sheet->setCellValue('D' . $row, 'Amarelo');
$sheet->getStyle('D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
$sheet->setCellValue('E' . $row, 'Média 5.0 - 9.4');
$sheet->setCellValue('F' . $row, 'Vermelho');
$sheet->getStyle('F' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
$sheet->setCellValue('G' . $row, 'Média < 5.0');

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
$colunas_range = range('A', 'L');
foreach ($colunas_range as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ============================================
// SALVAR ARQUIVO
// ============================================
$nome_arquivo = 'estatistica_disciplina_' . $disciplina['nome'] . '_' . $bimestre . 'B_' . date('Ymd_His') . '.xlsx';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nome_arquivo . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>