<?php
// escola/professor/relatorios/gerar_excel_pautas_gerais.php - Gerar Excel das Pautas Gerais

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
$sql_escola = "SELECT nome, endereco, telefone, email, nif FROM escolas WHERE id = :id";
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
$is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
$is_ensino_fundamental = ($classe_ano <= 6);
$limite_aprovacao = $is_ensino_fundamental ? 5 : 10;

// ============================================
// BUSCAR DADOS DA DISCIPLINA
// ============================================
$sql_disciplina = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

$disciplina_nome = $disciplina['nome'] ?? '';
$is_disciplina_lingua = (stripos($disciplina_nome, 'português') !== false || stripos($disciplina_nome, 'inglês') !== false);

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

// ============================================
// FUNÇÃO PARA COR DA NOTA
// ============================================
function getCorNotaExcel($nota, $is_ensino_fundamental) {
    if ($nota === null || $nota === '' || $nota <= 0) {
        return '';
    }
    
    if ($is_ensino_fundamental) {
        if ($nota >= 4.5) {
            return '28a745'; // Verde
        } else {
            return 'dc3545'; // Vermelho
        }
    } else {
        if ($nota >= 9.5) {
            return '28a745'; // Verde
        } else {
            return 'dc3545'; // Vermelho
        }
    }
}

// ============================================
// FUNÇÃO PARA CALCULAR MÉDIA DO 3º BIMESTRE
// ============================================
function calcularMediaFinal3BimestreExcel($mac, $npt, $exame_normal, $exame_recurso, $exame_oral, $exame_escrito, $is_classe_exame, $is_disciplina_lingua) {
    if ($is_classe_exame) {
        $media_parcial = $mac;
        
        if ($exame_recurso > 0) {
            return round($exame_recurso, 1);
        } else {
            if ($is_disciplina_lingua) {
                if ($exame_oral > 0 && $exame_escrito > 0) {
                    $media_exame = ($exame_oral + $exame_escrito) / 2;
                    return round(($media_parcial * 0.4) + ($media_exame * 0.6), 1);
                } elseif ($exame_oral > 0) {
                    return round(($media_parcial * 0.4) + ($exame_oral * 0.6), 1);
                } elseif ($exame_escrito > 0) {
                    return round(($media_parcial * 0.4) + ($exame_escrito * 0.6), 1);
                } else {
                    return round($media_parcial, 1);
                }
            } else {
                if ($exame_normal > 0) {
                    return round(($media_parcial * 0.4) + ($exame_normal * 0.6), 1);
                } else {
                    return round($media_parcial, 1);
                }
            }
        }
    } else {
        $media_parcial = ($mac + $npt) / 2;
        
        if ($exame_recurso > 0) {
            return round(($media_parcial + $exame_recurso) / 2, 1);
        } elseif ($exame_normal > 0) {
            return round(($media_parcial + $exame_normal) / 2, 1);
        } else {
            return round($media_parcial, 1);
        }
    }
}

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
            $media_calculada = calcularMediaFinal3BimestreExcel(
                $bim_data['mac'] ?? 0,
                $bim_data['npt'] ?? 0,
                $bim_data['exame_normal'] ?? 0,
                $bim_data['exame_recurso'] ?? 0,
                $bim_data['exame_oral'] ?? 0,
                $bim_data['exame_escrito'] ?? 0,
                $is_classe_exame,
                $is_disciplina_lingua
            );
            $bim_data['media_final'] = $media_calculada;
            
            if ($media_calculada > 0) {
                $soma_medias += $media_calculada;
                $bimestres_com_nota++;
            }
        }
    }
    
    $aluno['media_anual'] = $bimestres_com_nota > 0 ? round($soma_medias / $bimestres_com_nota, 1) : 0;
    
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

// Funções auxiliares
function getSituacaoTexto($situacao) {
    switch ($situacao) {
        case 'aprovado': return 'Aprovado';
        case 'recuperacao': return 'Recuperação';
        case 'reprovado': return 'Reprovado';
        default: return 'Pendente';
    }
}

function getNotaFormatadaExcel($nota) {
    if (is_null($nota) || $nota == 0) return '-';
    return number_format($nota, 1);
}

function getNotaFormatadaMediaExcel($nota) {
    if (is_null($nota) || $nota == 0) return '-';
    return number_format(round($nota, 1), 1);
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

// ============================================
// CABEÇALHO
// ============================================
$sheet->setCellValue('A1', strtoupper($escola['nome'] ?? 'SIGE ANGOLA'));
$sheet->mergeCells('A1:S1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A1')->getFont()->getColor()->setRGB('006B3E');

$sheet->setCellValue('A2', 'PAUTA GERAL DE NOTAS');
$sheet->mergeCells('A2:S2');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A2')->getFont()->getColor()->setRGB('1A2A6C');

$sheet->setCellValue('A3', $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . htmlspecialchars($disciplina['nome']) . ' | ANO LETIVO ' . $ano_letivo_ano);
$sheet->mergeCells('A3:S3');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A3')->getFont()->setSize(9);

// ============================================
// INFORMAÇÕES DA TURMA
// ============================================
$linha = 5;
$sheet->setCellValue('A' . $linha, 'INFORMAÇÕES DA TURMA');
$sheet->mergeCells('A' . $linha . ':S' . $linha);
$sheet->getStyle('A' . $linha)->getFont()->setBold(true)->setSize(10);
$sheet->getStyle('A' . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $linha)->getFont()->getColor()->setRGB('FFFFFF');

$linha++;
$sheet->setCellValue('A' . $linha, 'Turma:');
$sheet->setCellValue('B' . $linha, $turma['ano'] . 'ª ' . $turma['nome']);
$sheet->setCellValue('D' . $linha, 'Turno:');
$sheet->setCellValue('E' . $linha, ucfirst($turma['turno']));
$sheet->setCellValue('G' . $linha, 'Sala:');
$sheet->setCellValue('H' . $linha, $turma['sala'] ?: '-');
$sheet->setCellValue('J' . $linha, 'Ano Letivo:');
$sheet->setCellValue('K' . $linha, $ano_letivo_ano);
$sheet->setCellValue('M' . $linha, 'Data:');
$sheet->setCellValue('N' . $linha, date('d/m/Y'));

$linha++;
$sheet->setCellValue('A' . $linha, 'Disciplina:');
$sheet->setCellValue('B' . $linha, htmlspecialchars($disciplina['nome']));
$sheet->setCellValue('D' . $linha, 'Código:');
$sheet->setCellValue('E' . $linha, htmlspecialchars($disciplina['codigo'] ?? '-'));
$sheet->setCellValue('G' . $linha, 'Professor:');
$sheet->setCellValue('H' . $linha, htmlspecialchars($professor['professor_nome']));
$sheet->setCellValue('J' . $linha, 'Escala:');
$sheet->setCellValue('K' . $linha, $is_ensino_fundamental ? '0-10' : '0-20');

if ($is_classe_exame) {
    $sheet->setCellValue('M' . $linha, 'Classe Exame:');
    $sheet->setCellValue('N' . $linha, $classe_ano . 'ª');
}

$sheet->getStyle('A' . ($linha - 1) . ':N' . $linha)->getFont()->setSize(8);
for ($r = $linha - 1; $r <= $linha; $r++) {
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    $sheet->getStyle('D' . $r)->getFont()->setBold(true);
    $sheet->getStyle('G' . $r)->getFont()->setBold(true);
    $sheet->getStyle('J' . $r)->getFont()->setBold(true);
    if ($is_classe_exame) {
        $sheet->getStyle('M' . $r)->getFont()->setBold(true);
    }
}

// ============================================
// ESTATÍSTICAS
// ============================================
$linha += 2;
$sheet->setCellValue('A' . $linha, 'ESTATÍSTICAS');
$sheet->mergeCells('A' . $linha . ':S' . $linha);
$sheet->getStyle('A' . $linha)->getFont()->setBold(true)->setSize(10);
$sheet->getStyle('A' . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $linha)->getFont()->getColor()->setRGB('FFFFFF');

$linha++;
$sheet->setCellValue('A' . $linha, 'Total de Alunos:');
$sheet->setCellValue('B' . $linha, $total_alunos);
$sheet->setCellValue('D' . $linha, 'Aprovados:');
$sheet->setCellValue('E' . $linha, $total_aprovados . ' (' . $percentual_aprovacao . '%)');
$sheet->setCellValue('G' . $linha, 'Recuperação:');
$sheet->setCellValue('H' . $linha, $total_recuperacao);
$sheet->setCellValue('J' . $linha, 'Reprovados:');
$sheet->setCellValue('K' . $linha, $total_reprovados);
$sheet->setCellValue('M' . $linha, 'Média Anual:');
$sheet->setCellValue('N' . $linha, $media_geral_anual);

$sheet->getStyle('A' . $linha . ':N' . $linha)->getFont()->setSize(8);
$sheet->getStyle('A' . $linha)->getFont()->setBold(true);
$sheet->getStyle('D' . $linha)->getFont()->setBold(true);
$sheet->getStyle('G' . $linha)->getFont()->setBold(true);
$sheet->getStyle('J' . $linha)->getFont()->setBold(true);
$sheet->getStyle('M' . $linha)->getFont()->setBold(true);

// ============================================
// TABELA DE NOTAS
// ============================================
$linha += 2;
$sheet->setCellValue('A' . $linha, 'PAUTA DE NOTAS COMPLETA');
$sheet->mergeCells('A' . $linha . ':S' . $linha);
$sheet->getStyle('A' . $linha)->getFont()->setBold(true)->setSize(10);
$sheet->getStyle('A' . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $linha)->getFont()->getColor()->setRGB('FFFFFF');

$linha++;
// Cabeçalho principal
$sheet->setCellValue('A' . $linha, '#');
$sheet->setCellValue('B' . $linha, 'Aluno');
$sheet->setCellValue('C' . $linha, 'Matrícula');

$coluna_atual = 4;
for ($b = 1; $b <= 3; $b++) {
    if ($b == 3 && $is_classe_exame && $is_disciplina_lingua) {
        $sheet->mergeCells(chr(64 + $coluna_atual) . $linha . ':' . chr(64 + $coluna_atual + 4) . $linha);
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, $b . 'º Bimestre');
        $coluna_atual += 5;
    } elseif ($b == 3 && $is_classe_exame) {
        $sheet->mergeCells(chr(64 + $coluna_atual) . $linha . ':' . chr(64 + $coluna_atual + 3) . $linha);
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, $b . 'º Bimestre');
        $coluna_atual += 4;
    } else {
        $sheet->mergeCells(chr(64 + $coluna_atual) . $linha . ':' . chr(64 + $coluna_atual + 2) . $linha);
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, $b . 'º Bimestre');
        $coluna_atual += 3;
    }
}
$sheet->setCellValue(chr(64 + $coluna_atual) . $linha, 'Média');
$sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, 'Situação');
$coluna_atual += 2;

$linha++;
// Sub-cabeçalho
$sheet->setCellValue('A' . $linha, '#');
$sheet->setCellValue('B' . $linha, 'Aluno');
$sheet->setCellValue('C' . $linha, 'Matrícula');

$coluna_atual = 4;
for ($b = 1; $b <= 3; $b++) {
    if ($b == 3 && $is_classe_exame && $is_disciplina_lingua) {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, 'MAC');
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, 'NPT');
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $linha, 'Exame Oral');
        $sheet->setCellValue(chr(64 + $coluna_atual + 3) . $linha, 'Exame Escrito');
        $sheet->setCellValue(chr(64 + $coluna_atual + 4) . $linha, 'Média');
        $coluna_atual += 5;
    } elseif ($b == 3 && $is_classe_exame) {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, 'MAC');
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, 'NPT');
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $linha, 'Exame');
        $sheet->setCellValue(chr(64 + $coluna_atual + 3) . $linha, 'Média');
        $coluna_atual += 4;
    } else {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, 'MAC');
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, 'NPT');
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $linha, 'Média');
        $coluna_atual += 3;
    }
}
$sheet->setCellValue(chr(64 + $coluna_atual) . $linha, 'Anual');
$sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, 'Final');
$coluna_atual += 2;

// Estilo do cabeçalho
$sheet->getStyle('A' . ($linha - 1) . ':' . chr(64 + $coluna_atual + 1) . $linha)->getFont()->setBold(true);
$sheet->getStyle('A' . ($linha - 1) . ':' . chr(64 + $coluna_atual + 1) . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
$sheet->getStyle('A' . ($linha - 1) . ':' . chr(64 + $coluna_atual + 1) . $linha)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . ($linha - 1) . ':' . chr(64 + $coluna_atual + 1) . $linha)->getFont()->setSize(8);

$linha++;
// Dados dos alunos
$contador = 1;
foreach ($alunos as $aluno) {
    $b1 = $aluno['bimestres'][1];
    $b2 = $aluno['bimestres'][2];
    $b3 = $aluno['bimestres'][3];
    
    $sheet->setCellValue('A' . $linha, $contador++);
    $sheet->setCellValue('B' . $linha, htmlspecialchars($aluno['nome']));
    $sheet->setCellValue('C' . $linha, htmlspecialchars($aluno['matricula']));
    
    $coluna_atual = 4;
    
    // 1º Bimestre
    $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, getNotaFormatadaExcel($b1['mac']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, getNotaFormatadaExcel($b1['npt']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $linha, getNotaFormatadaMediaExcel($b1['media_final']));
    $coluna_atual += 3;
    
    // 2º Bimestre
    $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, getNotaFormatadaExcel($b2['mac']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, getNotaFormatadaExcel($b2['npt']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $linha, getNotaFormatadaMediaExcel($b2['media_final']));
    $coluna_atual += 3;
    
    // 3º Bimestre
    if ($is_classe_exame && $is_disciplina_lingua) {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, getNotaFormatadaExcel($b3['mac']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, getNotaFormatadaExcel($b3['npt']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $linha, getNotaFormatadaExcel($b3['exame_oral']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 3) . $linha, getNotaFormatadaExcel($b3['exame_escrito']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 4) . $linha, getNotaFormatadaMediaExcel($b3['media_final']));
        $coluna_atual += 5;
    } elseif ($is_classe_exame) {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, getNotaFormatadaExcel($b3['mac']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, getNotaFormatadaExcel($b3['npt']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $linha, getNotaFormatadaExcel($b3['exame_normal']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 3) . $linha, getNotaFormatadaMediaExcel($b3['media_final']));
        $coluna_atual += 4;
    } else {
        $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, getNotaFormatadaExcel($b3['mac']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, getNotaFormatadaExcel($b3['npt']));
        $sheet->setCellValue(chr(64 + $coluna_atual + 2) . $linha, getNotaFormatadaMediaExcel($b3['media_final']));
        $coluna_atual += 3;
    }
    
    $sheet->setCellValue(chr(64 + $coluna_atual) . $linha, getNotaFormatadaMediaExcel($aluno['media_anual']));
    $sheet->setCellValue(chr(64 + $coluna_atual + 1) . $linha, getSituacaoTexto($aluno['situacao_final']));
    
    // Aplicar cores nas notas
    $cor_mac1 = getCorNotaExcel($b1['mac'], $is_ensino_fundamental);
    $cor_media1 = getCorNotaExcel($b1['media_final'], $is_ensino_fundamental);
    $cor_mac2 = getCorNotaExcel($b2['mac'], $is_ensino_fundamental);
    $cor_media2 = getCorNotaExcel($b2['media_final'], $is_ensino_fundamental);
    $cor_mac3 = getCorNotaExcel($b3['mac'], $is_ensino_fundamental);
    $cor_media3 = getCorNotaExcel($b3['media_final'], $is_ensino_fundamental);
    $cor_media_anual = getCorNotaExcel($aluno['media_anual'], $is_ensino_fundamental);
    
    if ($cor_mac1) $sheet->getStyle(chr(64 + 4) . $linha)->getFont()->getColor()->setRGB($cor_mac1);
    if ($cor_mac2) $sheet->getStyle(chr(64 + 7) . $linha)->getFont()->getColor()->setRGB($cor_mac2);
    if ($cor_mac3) $sheet->getStyle(chr(64 + 10) . $linha)->getFont()->getColor()->setRGB($cor_mac3);
    if ($cor_media1) $sheet->getStyle(chr(64 + 6) . $linha)->getFont()->getColor()->setRGB($cor_media1);
    if ($cor_media2) $sheet->getStyle(chr(64 + 9) . $linha)->getFont()->getColor()->setRGB($cor_media2);
    if ($cor_media3) $sheet->getStyle(chr(64 + ($coluna_atual - 1)) . $linha)->getFont()->getColor()->setRGB($cor_media3);
    if ($cor_media_anual) $sheet->getStyle(chr(64 + $coluna_atual) . $linha)->getFont()->getColor()->setRGB($cor_media_anual);
    
    // Cor da linha baseada na situação final
    if ($aluno['situacao_final'] == 'aprovado') {
        $sheet->getStyle('A' . $linha . ':' . chr(64 + $coluna_atual + 1) . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
    } elseif ($aluno['situacao_final'] == 'recuperacao') {
        $sheet->getStyle('A' . $linha . ':' . chr(64 + $coluna_atual + 1) . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
    } elseif ($aluno['situacao_final'] == 'reprovado') {
        $sheet->getStyle('A' . $linha . ':' . chr(64 + $coluna_atual + 1) . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
    }
    
    $linha++;
}

// Aplicar bordas
$lastRow = $linha - 1;
$sheet->getStyle('A' . ($lastRow - count($alunos)) . ':' . chr(64 + $coluna_atual + 1) . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// ============================================
// LEGENDA
// ============================================
$linha += 2;
$sheet->setCellValue('A' . $linha, 'LEGENDA DE CORES E STATUS');
$sheet->mergeCells('A' . $linha . ':S' . $linha);
$sheet->getStyle('A' . $linha)->getFont()->setBold(true)->setSize(10);
$sheet->getStyle('A' . $linha)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $linha)->getFont()->getColor()->setRGB('FFFFFF');

$linha++;
$sheet->setCellValue('A' . $linha, 'Critérios de Aprovação:');
$sheet->getStyle('A' . $linha)->getFont()->setBold(true);
$sheet->setCellValue('B' . $linha, $is_ensino_fundamental ? 'Aprovado: > 5 | Recuperação: = 5 | Reprovado: < 5' : 'Aprovado: > 10 | Recuperação: = 10 | Reprovado: < 10');

$linha++;
$sheet->setCellValue('A' . $linha, 'Cores das Notas:');
$sheet->getStyle('A' . $linha)->getFont()->setBold(true);
$sheet->setCellValue('B' . $linha, 'Verde: Nota positiva (' . ($is_ensino_fundamental ? '≥ 4.5' : '≥ 9.5') . ')');
$sheet->setCellValue('D' . $linha, 'Vermelho: Nota negativa (' . ($is_ensino_fundamental ? '< 4.5' : '< 9.5') . ')');

$linha++;
$sheet->setCellValue('A' . $linha, 'Cores das Linhas:');
$sheet->getStyle('A' . $linha)->getFont()->setBold(true);
$sheet->setCellValue('B' . $linha, 'Verde Claro: Aprovado');
$sheet->setCellValue('D' . $linha, 'Amarelo Claro: Recuperação');
$sheet->setCellValue('F' . $linha, 'Vermelho Claro: Reprovado');

if ($is_classe_exame) {
    $linha++;
    $sheet->setCellValue('A' . $linha, 'Regra Classe Exame (3º Bimestre):');
    $sheet->getStyle('A' . $linha)->getFont()->setBold(true);
    if ($is_disciplina_lingua) {
        $sheet->setCellValue('B' . $linha, 'Média = (MAC × 40%) + ((Exame Oral + Exame Escrito)/2 × 60%)');
    } else {
        $sheet->setCellValue('B' . $linha, 'Média = (MAC × 40%) + (Exame × 60%)');
    }
}

// ============================================
// RODAPÉ
// ============================================
$linha += 2;
$sheet->setCellValue('A' . $linha, 'Documento emitido eletronicamente pelo SIGE Angola - ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A' . $linha . ':S' . $linha);
$sheet->getStyle('A' . $linha)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $linha)->getFont()->setSize(8);
$sheet->getStyle('A' . $linha)->getFont()->getColor()->setRGB('6c757d');

// Auto-size
foreach (range('A', 'S') as $col) {
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