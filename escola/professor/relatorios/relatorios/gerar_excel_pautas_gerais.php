<?php
// escola/professor/relatorios/gerar_excel_pautas_gerais.php - Gerar Excel das Pautas Gerais

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
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;

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
$classe_ano = $turma['ano'] ?? 0;

// ============================================
// BUSCAR DADOS DA DISCIPLINA
// ============================================
$sql_disciplina = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);
$disciplina_nome = strtolower($disciplina['nome'] ?? '');

// ============================================
// DETERMINAR REGRAS
// ============================================
$is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
$is_disciplina_lingua = (strpos($disciplina_nome, 'português') !== false || strpos($disciplina_nome, 'inglês') !== false);
$is_ensino_fundamental = ($classe_ano <= 6);

// Definir colunas da tabela baseado nas regras
$colunas = [];
$colunas_bimestre = [];

for ($b = 1; $b <= 3; $b++) {
    $colunas_bimestre[$b] = ['mac' => true, 'npt' => true];
    
    if ($is_classe_exame && $b == 3) {
        if ($is_disciplina_lingua) {
            $colunas_bimestre[$b]['exame_oral'] = true;
            $colunas_bimestre[$b]['exame_escrito'] = true;
        } else {
            $colunas_bimestre[$b]['exame_normal'] = true;
        }
    } else {
        $colunas_bimestre[$b]['exame_normal'] = false;
    }
}

// ============================================
// BUSCAR ALUNOS E NOTAS (TODOS OS BIMESTRES)
// ============================================
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        n.bimestre,
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
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome, n.bimestre
";

$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$notas_raw = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Organizar notas por aluno e bimestre
$alunos_data = [];
foreach ($notas_raw as $nota) {
    $aluno_id = $nota['id'];
    if (!isset($alunos_data[$aluno_id])) {
        $alunos_data[$aluno_id] = [
            'nome' => $nota['nome'],
            'matricula' => $nota['matricula'],
            'bimestres' => []
        ];
    }
    $bim = $nota['bimestre'];
    if ($bim) {
        $alunos_data[$aluno_id]['bimestres'][$bim] = [
            'mac' => $nota['mac'],
            'npt' => $nota['npt'],
            'exame_normal' => $nota['exame_normal'],
            'exame_recurso' => $nota['exame_recurso'],
            'exame_especial' => $nota['exame_especial'],
            'exame_oral' => $nota['exame_oral'],
            'exame_escrito' => $nota['exame_escrito'],
            'media_final' => $nota['media_final'],
            'situacao' => $nota['situacao']
        ];
    }
}

// Função para calcular média final com regras
function calcularMediaFinalComRegras($mac, $npt, $bimestre, $is_classe_exame, $is_disciplina_lingua, $exame_normal, $exame_recurso, $exame_oral, $exame_escrito) {
    $media_parcial = ($mac + $npt) / 2;
    $media_final = $media_parcial;
    
    if ($bimestre == 3 && $is_classe_exame) {
        if ($is_disciplina_lingua) {
            if ($exame_oral > 0 && $exame_escrito > 0) {
                $media_exame = ($exame_oral + $exame_escrito) / 2;
                $media_final = ($media_parcial * 0.4) + ($media_exame * 0.6);
            } elseif ($exame_recurso > 0) {
                $media_final = ($media_parcial * 0.4) + ($exame_recurso * 0.6);
            }
        } else {
            if ($exame_normal > 0) {
                $media_final = ($media_parcial * 0.4) + ($exame_normal * 0.6);
            } elseif ($exame_recurso > 0) {
                $media_final = ($media_parcial * 0.4) + ($exame_recurso * 0.6);
            }
        }
    }
    
    return $media_final;
}

// Processar alunos e calcular médias
foreach ($alunos_data as $aluno_id => &$aluno) {
    $soma_medias = 0;
    $bimestres_com_nota = 0;
    
    for ($b = 1; $b <= 3; $b++) {
        if (!isset($aluno['bimestres'][$b])) {
            $aluno['bimestres'][$b] = [
                'mac' => null,
                'npt' => null,
                'exame_normal' => null,
                'exame_recurso' => null,
                'exame_oral' => null,
                'exame_escrito' => null,
                'media_final' => 0,
                'situacao' => 'pendente'
            ];
        } else {
            $bim_data = &$aluno['bimestres'][$b];
            $media_calculada = calcularMediaFinalComRegras(
                $bim_data['mac'] ?? 0,
                $bim_data['npt'] ?? 0,
                $b,
                $is_classe_exame,
                $is_disciplina_lingua,
                $bim_data['exame_normal'] ?? 0,
                $bim_data['exame_recurso'] ?? 0,
                $bim_data['exame_oral'] ?? 0,
                $bim_data['exame_escrito'] ?? 0
            );
            $bim_data['media_final'] = $media_calculada;
            
            if ($media_calculada > 0) {
                $soma_medias += $media_calculada;
                $bimestres_com_nota++;
            }
        }
    }
    
    $aluno['media_anual'] = $bimestres_com_nota > 0 ? round($soma_medias / $bimestres_com_nota, 1) : 0;
    
    $limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
    if ($aluno['media_anual'] > $limite_aprovacao) {
        $aluno['situacao_final'] = 'aprovado';
    } elseif ($aluno['media_anual'] == $limite_aprovacao && $aluno['media_anual'] > 0) {
        $aluno['situacao_final'] = 'recuperacao';
    } elseif ($aluno['media_anual'] > 0) {
        $aluno['situacao_final'] = 'reprovado';
    } else {
        $aluno['situacao_final'] = 'pendente';
    }
}
unset($aluno);
$alunos = $alunos_data;

// Estatísticas
$total_alunos = count($alunos);
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_medias_anuais = 0;

foreach ($alunos as $aluno) {
    if ($aluno['situacao_final'] == 'aprovado') $total_aprovados++;
    elseif ($aluno['situacao_final'] == 'recuperacao') $total_recuperacao++;
    elseif ($aluno['situacao_final'] == 'reprovado') $total_reprovados++;
    $soma_medias_anuais += $aluno['media_anual'];
}
$media_geral_anual = $total_alunos > 0 ? round($soma_medias_anuais / $total_alunos, 1) : 0;
$percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;

// Funções
function getSituacaoTexto($situacao) {
    switch ($situacao) {
        case 'aprovado': return 'Aprovado';
        case 'recuperacao': return 'Recuperação';
        case 'reprovado': return 'Reprovado';
        default: return 'Pendente';
    }
}

function getNotaFormatada($nota) {
    if (is_null($nota) || $nota == 0) return '-';
    return number_format($nota, 1);
}

// Criar planilha
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Pauta Geral');

// Configuração de página A4 paisagem
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageMargins()->setTop(0.75);
$sheet->getPageMargins()->setBottom(0.75);
$sheet->getPageMargins()->setLeft(0.5);
$sheet->getPageMargins()->setRight(0.5);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

// Cabeçalho
$sheet->setCellValue('A1', strtoupper($escola['nome'] ?? 'SIGE ANGOLA'));
$sheet->mergeCells('A1:R1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A2', 'PAUTA GERAL DE NOTAS');
$sheet->mergeCells('A2:R2');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A3', $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . htmlspecialchars($disciplina['nome']) . ' | ANO LETIVO ' . $ano_letivo_ano);
$sheet->mergeCells('A3:R3');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A3')->getFont()->setSize(9);

// Informações da turma
$sheet->setCellValue('A5', 'INFORMAÇÕES DA TURMA');
$sheet->mergeCells('A5:R5');
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

$sheet->setCellValue('A7', 'Disciplina:');
$sheet->setCellValue('B7', htmlspecialchars($disciplina['nome']));
$sheet->setCellValue('D7', 'Código:');
$sheet->setCellValue('E7', htmlspecialchars($disciplina['codigo'] ?? '-'));
$sheet->setCellValue('G7', 'Professor:');
$sheet->setCellValue('H7', htmlspecialchars($professor['professor_nome']));
$sheet->setCellValue('J7', 'Data Emissão:');
$sheet->setCellValue('K7', date('d/m/Y H:i:s'));

// Regras
$sheet->setCellValue('M6', 'Escala:');
$sheet->setCellValue('N6', $is_ensino_fundamental ? '0-10' : '0-20');
if ($is_classe_exame) {
    $sheet->setCellValue('M7', 'Classe Exame:');
    $sheet->setCellValue('N7', $classe_ano . 'ª (40% + 60%)');
    if ($is_disciplina_lingua) {
        $sheet->setCellValue('P6', 'Língua:');
        $sheet->setCellValue('P7', 'Oral + Escrito');
    }
}

$sheet->getStyle('A6:K7')->getFont()->setSize(8);
$sheet->getStyle('A6')->getFont()->setBold(true);
$sheet->getStyle('D6')->getFont()->setBold(true);
$sheet->getStyle('G6')->getFont()->setBold(true);
$sheet->getStyle('J6')->getFont()->setBold(true);
$sheet->getStyle('A7')->getFont()->setBold(true);
$sheet->getStyle('D7')->getFont()->setBold(true);
$sheet->getStyle('G7')->getFont()->setBold(true);
$sheet->getStyle('J7')->getFont()->setBold(true);
$sheet->getStyle('M6')->getFont()->setBold(true);
$sheet->getStyle('M7')->getFont()->setBold(true);

// Estatísticas
$sheet->setCellValue('A9', 'ESTATÍSTICAS');
$sheet->mergeCells('A9:R9');
$sheet->getStyle('A9')->getFont()->setBold(true);
$sheet->getStyle('A9')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A9')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A10', 'Total de Alunos:');
$sheet->setCellValue('B10', $total_alunos);
$sheet->setCellValue('D10', 'Aprovados:');
$sheet->setCellValue('E10', $total_aprovados . ' (' . $percentual_aprovacao . '%)');
$sheet->setCellValue('G10', 'Recuperação:');
$sheet->setCellValue('H10', $total_recuperacao);
$sheet->setCellValue('J10', 'Reprovados:');
$sheet->setCellValue('K10', $total_reprovados);
$sheet->setCellValue('M10', 'Média Anual:');
$sheet->setCellValue('N10', $media_geral_anual);

$sheet->getStyle('A10:N10')->getFont()->setSize(8);
$sheet->getStyle('A10')->getFont()->setBold(true);
$sheet->getStyle('D10')->getFont()->setBold(true);
$sheet->getStyle('G10')->getFont()->setBold(true);
$sheet->getStyle('J10')->getFont()->setBold(true);
$sheet->getStyle('M10')->getFont()->setBold(true);

// Tabela de notas
$row = 12;

$sheet->setCellValue('A' . $row, 'PAUTA DE NOTAS COMPLETA');
$sheet->mergeCells('A' . $row . ':R' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
// Cabeçalho principal
$sheet->setCellValue('A' . $row, '#');
$sheet->setCellValue('B' . $row, 'Aluno');
$sheet->setCellValue('C' . $row, 'Matrícula');

$coluna_atual = 4; // D

for ($b = 1; $b <= 3; $b++) {
    $col_inicio = chr(64 + $coluna_atual);
    if ($is_classe_exame && $b == 3 && $is_disciplina_lingua) {
        $sheet->mergeCells($col_inicio . $row . ':' . chr(64 + $coluna_atual + 4) . $row);
        $sheet->setCellValue($col_inicio . $row, $b . 'º Bimestre');
        $coluna_atual += 5;
    } elseif ($is_classe_exame && $b == 3) {
        $sheet->mergeCells($col_inicio . $row . ':' . chr(64 + $coluna_atual + 3) . $row);
        $sheet->setCellValue($col_inicio . $row, $b . 'º Bimestre');
        $coluna_atual += 4;
    } else {
        $sheet->mergeCells($col_inicio . $row . ':' . chr(64 + $coluna_atual + 2) . $row);
        $sheet->setCellValue($col_inicio . $row, $b . 'º Bimestre');
        $coluna_atual += 3;
    }
}
$sheet->setCellValue(chr(64 + $coluna_atual) . $row, 'Média Anual');
$sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, 'Situação');
$coluna_atual += 2;

$row++;
// Sub-cabeçalho
$sheet->setCellValue('A' . $row, '#');
$sheet->setCellValue('B' . $row, 'Aluno');
$sheet->setCellValue('C' . $row, 'Matrícula');

$coluna_atual = 4;
for ($b = 1; $b <= 3; $b++) {
    if ($is_classe_exame && $b == 3 && $is_disciplina_lingua) {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $row, 'MAC');
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, 'NPT');
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $row, 'Exame Oral');
        $sheet->setCellValue(chr(64 + $coluna_atual + 3) . $row, 'Exame Escrito');
        $sheet->setCellValue(chr(64 + $coluna_atual + 4) . $row, 'Média');
        $coluna_atual += 5;
    } elseif ($is_classe_exame && $b == 3) {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $row, 'MAC');
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, 'NPT');
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $row, 'Exame Normal');
        $sheet->setCellValue(chr(64 + $coluna_atual + 3) . $row, 'Média');
        $coluna_atual += 4;
    } else {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $row, 'MAC');
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, 'NPT');
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $row, 'Média');
        $coluna_atual += 3;
    }
}
$sheet->setCellValue(chr(64 + $coluna_atual) . $row, 'Final');
$sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, 'Final');

// Estilo do cabeçalho
$sheet->getStyle('A' . ($row - 1) . ':' . chr(64 + $coluna_atual + 1) . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . ($row - 1) . ':' . chr(64 + $coluna_atual + 1) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
$sheet->getStyle('A' . ($row - 1) . ':' . chr(64 + $coluna_atual + 1) . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . ($row - 1) . ':' . chr(64 + $coluna_atual + 1) . $row)->getFont()->setSize(8);

$row++;
$contador = 1;
foreach ($alunos as $aluno) {
    $b1 = $aluno['bimestres'][1];
    $b2 = $aluno['bimestres'][2];
    $b3 = $aluno['bimestres'][3];
    
    $sheet->setCellValue('A' . $row, $contador++);
    $sheet->setCellValue('B' . $row, htmlspecialchars($aluno['nome']));
    $sheet->setCellValue('C' . $row, htmlspecialchars($aluno['matricula']));
    
    $coluna_atual = 4;
    
    // 1º Bimestre (só MAC e NPT)
    $sheet->setCellValue(chr(64 + $coluna_atual) . $row, getNotaFormatada($b1['mac']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, getNotaFormatada($b1['npt']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $row, getNotaFormatada($b1['media_final']));
    $coluna_atual += 3;
    
    // 2º Bimestre (só MAC e NPT)
    $sheet->setCellValue(chr(64 + $coluna_atual) . $row, getNotaFormatada($b2['mac']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, getNotaFormatada($b2['npt']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $row, getNotaFormatada($b2['media_final']));
    $coluna_atual += 3;
    
    // 3º Bimestre (com ou sem exame)
    if ($is_classe_exame && $is_disciplina_lingua) {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $row, getNotaFormatada($b3['mac']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, getNotaFormatada($b3['npt']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $row, getNotaFormatada($b3['exame_oral']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 3) . $row, getNotaFormatada($b3['exame_escrito']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 4) . $row, getNotaFormatada($b3['media_final']));
        $coluna_atual += 5;
    } elseif ($is_classe_exame) {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $row, getNotaFormatada($b3['mac']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, getNotaFormatada($b3['npt']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $row, getNotaFormatada($b3['exame_normal']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 3) . $row, getNotaFormatada($b3['media_final']));
        $coluna_atual += 4;
    } else {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $row, getNotaFormatada($b3['mac']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, getNotaFormatada($b3['npt']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $row, getNotaFormatada($b3['media_final']));
        $coluna_atual += 3;
    }
    
    // Média Anual e Situação
    $sheet->setCellValue(chr(64 + $coluna_atual) . $row, number_format($aluno['media_anual'], 1));
    $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $row, getSituacaoTexto($aluno['situacao_final']));
    
    // Cores
    if ($aluno['situacao_final'] == 'aprovado') {
        $sheet->getStyle('A' . $row . ':' . chr(64 + $coluna_atual + 1) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
    } elseif ($aluno['situacao_final'] == 'recuperacao') {
        $sheet->getStyle('A' . $row . ':' . chr(64 + $coluna_atual + 1) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
    } elseif ($aluno['situacao_final'] == 'reprovado') {
        $sheet->getStyle('A' . $row . ':' . chr(64 + $coluna_atual + 1) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
    }
    
    $row++;
}

// Aplicar bordas
$lastRow = $row - 1;
$sheet->getStyle('A' . 12 . ':' . chr(64 + $coluna_atual + 1) . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Legenda
$row += 2;
$sheet->setCellValue('A' . $row, 'LEGENDA');
$sheet->mergeCells('A' . $row . ':R' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$sheet->setCellValue('A' . $row, 'Critérios de Aprovação:');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->setCellValue('B' . $row, $is_ensino_fundamental ? 'Aprovado: > 5 | Recuperação: = 5 | Reprovado: < 5' : 'Aprovado: > 10 | Recuperação: = 10 | Reprovado: < 10');

if ($is_classe_exame) {
    $row++;
    $sheet->setCellValue('A' . $row, 'Regra Classe Exame:');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->setCellValue('B' . $row, 'Média Final do 3º Bimestre = (MAC + NPT)/2 × 0.4 + Exame × 0.6');
}

// Rodapé
$row += 2;
$sheet->setCellValue('A' . $row, 'Documento emitido eletronicamente pelo SIGE Angola - ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A' . $row . ':R' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(8);

// Auto-size
foreach (range('A', 'R') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Salvar arquivo
$nome_arquivo = 'pauta_geral_' . $turma['ano'] . '_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . date('Ymd_His') . '.xlsx';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nome_arquivo . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>