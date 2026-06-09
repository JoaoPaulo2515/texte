<?php
// escola/professor/gerar_excel_chamada.php - Gerar Excel da Chamada usando PhpSpreadsheet

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

// Carregar autoload do PhpSpreadsheet (se instalado via Composer)
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// RECEBER PARÂMETROS
// ============================================
$turma_id = (int)($_GET['turma_id'] ?? 0);
$disciplina_id = (int)($_GET['disciplina_id'] ?? 0);
$data_aula = $_GET['data'] ?? date('Y-m-d');

// ============================================
// VERIFICAR PERMISSÃO
// ============================================
if ($turma_id == 0 || $disciplina_id == 0) {
    die("Parâmetros inválidos.");
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar dados do professor
$sql_professor = "SELECT u.nome FROM professores p INNER JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = :professor_id";
$stmt_prof = $conn->prepare($sql_professor);
$stmt_prof->execute([':professor_id' => $professor_id]);
$professor_nome = $stmt_prof->fetch(PDO::FETCH_ASSOC)['nome'] ?? 'Professor';

// Buscar dados da turma
$sql_turma = "SELECT nome, ano, turno, sala FROM turmas WHERE id = :turma_id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Buscar dados da disciplina
$sql_disciplina = "SELECT nome, codigo FROM disciplinas WHERE id = :disciplina_id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':disciplina_id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// Buscar alunos e suas presenças
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.numero_processo,
        COALESCE(c.status, 'presente') as status,
        COALESCE(c.observacao, '') as observacao,
        c.created_at as hora_registro
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN chamada c ON c.estudante_id = e.id 
        AND c.turma_id = m.turma_id 
        AND c.disciplina_id = :disciplina_id 
        AND c.data_aula = :data_aula
    WHERE m.turma_id = :turma_id 
    AND m.status = 'ativa'
    ORDER BY e.nome
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':data_aula' => $data_aula
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_alunos = count($alunos);
$presentes = 0;
$faltas = 0;
$atrasos = 0;
$justificados = 0;

foreach ($alunos as $aluno) {
    switch ($aluno['status']) {
        case 'presente': $presentes++; break;
        case 'falta': $faltas++; break;
        case 'atraso': $atrasos++; break;
        case 'justificado': $justificados++; break;
    }
}
$percentual_presenca = $total_alunos > 0 ? round(($presentes / $total_alunos) * 100, 1) : 0;

// ============================================
// CRIAR PLANILHA EXCEL
// ============================================

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// ============================================
// CONFIGURAÇÕES DA PÁGINA
// ============================================
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageMargins()->setTop(0.75);
$sheet->getPageMargins()->setBottom(0.75);
$sheet->getPageMargins()->setLeft(0.5);
$sheet->getPageMargins()->setRight(0.5);

// ============================================
// CABEÇALHO DA PLANILHA
// ============================================

// Título da Escola
$sheet->setCellValue('A1', strtoupper($escola['nome'] ?? 'SIGE ANGOLA'));
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells('A1:G1');

// Endereço da Escola
$sheet->setCellValue('A2', $escola['endereco'] ?? '');
$sheet->getStyle('A2')->getFont()->setSize(10);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells('A2:G2');

// Contato
$sheet->setCellValue('A3', 'Tel: ' . ($escola['telefone'] ?? '') . ' | Email: ' . ($escola['email'] ?? ''));
$sheet->getStyle('A3')->getFont()->setSize(10);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells('A3:G3');

// Título do Relatório
$sheet->setCellValue('A5', 'RELATÓRIO DE CHAMADA');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells('A5:G5');

// Linha em branco
$sheet->setCellValue('A6', '');

// ============================================
// INFORMAÇÕES DA CHAMADA
// ============================================

$linha = 7;

// Tabela de informações
$info_dados = [
    ['Turma:', $turma['nome'] ?? '-', 'Classe:', ($turma['ano'] ?? '0') . 'ª Classe'],
    ['Turno:', ucfirst($turma['turno'] ?? '-'), 'Sala:', $turma['sala'] ?? '-'],
    ['Disciplina:', $disciplina['nome'] ?? '-', 'Código:', $disciplina['codigo'] ?? '-'],
    ['Data da Aula:', date('d/m/Y', strtotime($data_aula)), 'Professor:', $professor_nome],
    ['Data de Emissão:', date('d/m/Y H:i:s'), 'Gerado por:', $_SESSION['usuario_nome'] ?? 'Sistema']
];

foreach ($info_dados as $row) {
    $sheet->setCellValue('A' . $linha, $row[0]);
    $sheet->setCellValue('B' . $linha, $row[1]);
    $sheet->setCellValue('C' . $linha, $row[2]);
    $sheet->setCellValue('D' . $linha, $row[3]);
    
    $sheet->getStyle('A' . $linha)->getFont()->setBold(true);
    $sheet->getStyle('C' . $linha)->getFont()->setBold(true);
    
    $linha++;
}

$linha += 1;

// ============================================
// ESTATÍSTICAS
// ============================================

$sheet->setCellValue('A' . $linha, 'ESTATÍSTICAS DA CHAMADA');
$sheet->getStyle('A' . $linha)->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A' . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF006B3E');
$sheet->getStyle('A' . $linha)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
$sheet->mergeCells('A' . $linha . ':G' . $linha);
$linha++;

$sheet->setCellValue('A' . $linha, 'Total de Alunos');
$sheet->setCellValue('B' . $linha, $total_alunos);
$sheet->setCellValue('C' . $linha, 'Presentes');
$sheet->setCellValue('D' . $linha, $presentes);
$sheet->setCellValue('E' . $linha, 'Faltas');
$sheet->setCellValue('F' . $linha, $faltas);
$sheet->setCellValue('G' . $linha, '% Presença');
$sheet->setCellValue('H' . $linha, $percentual_presenca . '%');

$sheet->getStyle('A' . $linha)->getFont()->setBold(true);
$sheet->getStyle('C' . $linha)->getFont()->setBold(true);
$sheet->getStyle('E' . $linha)->getFont()->setBold(true);
$sheet->getStyle('G' . $linha)->getFont()->setBold(true);

$sheet->getStyle('B' . $linha)->getFont()->setBold(true)->getColor()->setARGB('FF006B3E');
$sheet->getStyle('D' . $linha)->getFont()->setBold(true)->getColor()->setARGB('FF28a745');
$sheet->getStyle('F' . $linha)->getFont()->setBold(true)->getColor()->setARGB('FFdc3545');
$sheet->getStyle('H' . $linha)->getFont()->setBold(true)->getColor()->setARGB('FF006B3E');

$linha++;

// Detalhes adicionais
$sheet->setCellValue('A' . $linha, 'Atrasos: ' . $atrasos);
$sheet->setCellValue('C' . $linha, 'Justificados: ' . $justificados);
$sheet->setCellValue('E' . $linha, 'Taxa de Aproveitamento: ' . $percentual_presenca . '%');

$sheet->getStyle('A' . $linha)->getFont()->setItalic(true);
$sheet->getStyle('C' . $linha)->getFont()->setItalic(true);
$sheet->getStyle('E' . $linha)->getFont()->setItalic(true);

$linha += 2;

// ============================================
// TABELA DE ALUNOS
// ============================================

// Cabeçalho da tabela
$cabecalho = ['Nº', 'Nome do Aluno', 'Matrícula/Processo', 'Status', 'Observação', 'Hora Registro'];
$colunas = ['A', 'B', 'C', 'D', 'E', 'F'];

foreach ($cabecalho as $i => $titulo) {
    $sheet->setCellValue($colunas[$i] . $linha, $titulo);
    $sheet->getStyle($colunas[$i] . $linha)->getFont()->setBold(true);
    $sheet->getStyle($colunas[$i] . $linha)->getFont()->getColor()->setARGB(Color::COLOR_WHITE);
    $sheet->getStyle($colunas[$i] . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF006B3E');
    $sheet->getStyle($colunas[$i] . $linha)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getColumnDimension($colunas[$i])->setAutoSize(true);
}

$linha++;

// Dados dos alunos
$contador = 1;
foreach ($alunos as $aluno) {
    $status_texto = '';
    $status_cor = '';
    
    switch ($aluno['status']) {
        case 'presente':
            $status_texto = 'Presente';
            $status_cor = 'FFd4edda';
            break;
        case 'falta':
            $status_texto = 'Falta';
            $status_cor = 'FFf8d7da';
            break;
        case 'atraso':
            $status_texto = 'Atraso';
            $status_cor = 'FFFFF3CD';
            break;
        case 'justificado':
            $status_texto = 'Justificado';
            $status_cor = 'FFd1ecf1';
            break;
        default:
            $status_texto = ucfirst($aluno['status']);
            $status_cor = 'FFf8f9fa';
    }
    
    $sheet->setCellValue('A' . $linha, $contador);
    $sheet->setCellValue('B' . $linha, $aluno['nome']);
    $sheet->setCellValue('C' . $linha, $aluno['matricula'] ?: $aluno['numero_processo']);
    $sheet->setCellValue('D' . $linha, $status_texto);
    $sheet->setCellValue('E' . $linha, $aluno['observacao']);
    $sheet->setCellValue('F' . $linha, $aluno['hora_registro'] ? date('H:i:s', strtotime($aluno['hora_registro'])) : '-');
    
    // Aplicar cores conforme status
    $sheet->getStyle('D' . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($status_cor);
    $sheet->getStyle('D' . $linha)->getFont()->setBold(true);
    
    $contador++;
    $linha++;
}

// Bordas da tabela
$linha_final = $linha - 1;
$sheet->getStyle('A7:F' . $linha_final)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// ============================================
// RODAPÉ
// ============================================
$linha += 2;

$sheet->setCellValue('A' . $linha, '_________________________________________');
$sheet->mergeCells('A' . $linha . ':B' . $linha);
$linha++;

$sheet->setCellValue('A' . $linha, 'Assinatura do Professor');
$sheet->getStyle('A' . $linha)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells('A' . $linha . ':B' . $linha);

$sheet->setCellValue('E' . $linha, '_________________________________________');
$sheet->mergeCells('E' . $linha . ':F' . $linha);
$linha++;

$sheet->setCellValue('E' . $linha, 'Carimbo da Escola');
$sheet->getStyle('E' . $linha)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells('E' . $linha . ':F' . $linha);

$linha += 2;

$sheet->setCellValue('A' . $linha, 'Documento gerado eletronicamente por SIGE Angola em ' . date('d/m/Y H:i:s'));
$sheet->getStyle('A' . $linha)->getFont()->setSize(8);
$sheet->getStyle('A' . $linha)->getFont()->getColor()->setARGB('FF999999');
$sheet->mergeCells('A' . $linha . ':F' . $linha);

// ============================================
// CONFIGURAR IMPRESSÃO
// ============================================
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);
$sheet->setShowGridlines(false);
$sheet->getPageSetup()->setPrintArea('A1:F' . $linha);

// ============================================
// GERAR ARQUIVO PARA DOWNLOAD
// ============================================

$nome_arquivo = 'chamada_' . date('Ymd_His') . '.xlsx';

// Headers para download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nome_arquivo . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

// Criar writer e enviar para saída
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>