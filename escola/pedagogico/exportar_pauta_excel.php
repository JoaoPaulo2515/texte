<?php
// escola/pedagogico/exportar_pauta_excel.php - Exportar Pauta Final para Excel

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

$ano_selecionado = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$turma_selecionada = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'Anual';
$sala = isset($_GET['sala']) ? $_GET['sala'] : '';

if (!$turma_selecionada) {
    die('Turma não selecionada');
}

// Buscar informações da turma e escola
$sql_info = "
    SELECT t.id, t.nome as turma_nome, c.nome as classe_nome, c.ordem as classe_ordem,
           e.nome as escola_nome, e.endereco, e.telefone, e.email, e.nif
    FROM turmas t
    INNER JOIN classes c ON c.id = t.classe_id
    INNER JOIN escolas e ON e.id = t.escola_id
    WHERE t.id = :turma_id
";
$stmt_info = $conn->prepare($sql_info);
$stmt_info->execute([':turma_id' => $turma_selecionada]);
$info = $stmt_info->fetch(PDO::FETCH_ASSOC);

// Buscar o nome completo da escola do sistema
$sql_escola_nome = "SELECT nome FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola_nome);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola_nome_completo = $stmt_escola->fetch(PDO::FETCH_ASSOC);
$nome_escola = $escola_nome_completo ? $escola_nome_completo['nome'] : 'Colégio Pombal-Nº 4324';

// Determinar a classe (inferior a 7ª ou superior a 6ª)
$classe_ordem = $info['classe_ordem'];
$is_classe_inferior = $classe_ordem <= 6;

// Buscar disciplinas da turma
$sql_disciplinas = "
    SELECT DISTINCT d.id, d.nome, d.codigo
    FROM disciplinas d
    INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
    WHERE pdt.turma_id = :turma_id
    ORDER BY d.nome ASC
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':turma_id' => $turma_selecionada]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos da turma
$sql_alunos = "
    SELECT a.id, a.nome, a.matricula, a.bi
    FROM matriculas m
    INNER JOIN estudantes a ON a.id = m.estudante_id
    WHERE m.turma_id = :turma_id 
    AND m.ano_letivo = (SELECT id FROM ano_letivo WHERE ano = :ano AND escola_id = :escola_id LIMIT 1)
    AND m.status = 'ativa'
    ORDER BY a.nome ASC
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_selecionada,
    ':ano' => $ano_selecionado,
    ':escola_id' => $escola_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Função para determinar a cor da nota
function getCorNota($nota, $is_classe_inferior) {
    if ($is_classe_inferior) {
        if ($nota <= 4.4) {
            return 'vermelho';
        } else {
            return 'azul';
        }
    } else {
        if ($nota <= 9.4) {
            return 'vermelho';
        } else {
            return 'azul';
        }
    }
}

// Processar notas para cada aluno
foreach ($alunos as $key => $aluno) {
    $sql_notas = "
        SELECT disciplina_id, bimestre,
               mac, npt, 
               exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito,
               media_parcial, media_final, status
        FROM notas 
        WHERE estudante_id = :aluno_id 
        AND turma_id = :turma_id
        AND ano_letivo_id = (SELECT id FROM ano_letivo WHERE ano = :ano AND escola_id = :escola_id LIMIT 1)
        ORDER BY disciplina_id, bimestre
    ";
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute([
        ':aluno_id' => $aluno['id'],
        ':turma_id' => $turma_selecionada,
        ':ano' => $ano_selecionado,
        ':escola_id' => $escola_id
    ]);
    $notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
    $notas_por_disciplina = [];
    
    foreach ($disciplinas as $disc) {
        $disc_id = $disc['id'];
        $notas_por_disciplina[$disc_id] = [
            'bimestres' => [],
            'media_anual' => 0
        ];
    }
    
    foreach ($notas as $nota) {
        $disc_id = $nota['disciplina_id'];
        $bimestre = (int)$nota['bimestre'];
        
        $componentes = 0;
        $soma = 0;
        
        if ($nota['mac'] !== null) {
            $soma += (float)$nota['mac'];
            $componentes++;
        }
        if ($nota['npt'] !== null) {
            $soma += (float)$nota['npt'];
            $componentes++;
        }
        
        $media_bimestre = $componentes > 0 ? $soma / $componentes : 0;
        $notas_por_disciplina[$disc_id]['bimestres'][$bimestre] = round($media_bimestre, 1);
    }
    
    foreach ($notas_por_disciplina as $disc_id => &$disciplina_data) {
        $soma_bimestres = 0;
        for ($b = 1; $b <= 3; $b++) {
            if (isset($disciplina_data['bimestres'][$b]) && $disciplina_data['bimestres'][$b] !== null) {
                $soma_bimestres += $disciplina_data['bimestres'][$b];
            }
        }
        $disciplina_data['media_anual'] = round($soma_bimestres / 3, 1);
    }
    
    $alunos[$key]['notas_por_disciplina'] = $notas_por_disciplina;
    
    $soma_geral = 0;
    $count_disciplinas = count($disciplinas);
    foreach ($notas_por_disciplina as $disciplina_data) {
        $soma_geral += $disciplina_data['media_anual'];
    }
    $media_geral = $count_disciplinas > 0 ? round($soma_geral / $count_disciplinas, 1) : 0;
    $alunos[$key]['media_geral'] = $media_geral;
    
    if ($media_geral >= 10) {
        $alunos[$key]['resultado_geral'] = 'Aprovado';
    } elseif ($media_geral >= 7) {
        $alunos[$key]['resultado_geral'] = 'Recuperação';
    } else {
        $alunos[$key]['resultado_geral'] = 'Reprovado';
    }
}

$dia = date('d');
$mes_nomes = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$mes = $mes_nomes[(int)date('m')];
$ano = date('Y');

// Carregar PHPSpreadsheet
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

// Criar nova planilha
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Configurar página A3 paisagem
$spreadsheet->getActiveSheet()->getPageSetup()
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3)
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

// Calcular o número total de colunas
$num_disciplinas = count($disciplinas);
$total_colunas = 3 + ($num_disciplinas * 4) + 2; // Colunas A, B, C + (disciplinas * 4) + Média Geral + Resultado
$ultima_coluna_letra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($total_colunas);

// Definir larguras das colunas
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(12);
$sheet->getColumnDimension('C')->setWidth(35);

$coluna_atual = 'D';
foreach ($disciplinas as $disc) {
    $sheet->getColumnDimension($coluna_atual)->setWidth(6);
    $sheet->getColumnDimension(chr(ord($coluna_atual) + 1))->setWidth(6);
    $sheet->getColumnDimension(chr(ord($coluna_atual) + 2))->setWidth(6);
    $sheet->getColumnDimension(chr(ord($coluna_atual) + 3))->setWidth(7);
    $coluna_atual = chr(ord($coluna_atual) + 4);
}
$sheet->getColumnDimension($coluna_atual)->setWidth(10);
$sheet->getColumnDimension(chr(ord($coluna_atual) + 1))->setWidth(12);

// Linha inicial
$linha = 1;

// ============================================
// CABEÇALHO COM MESCLAGEM ATÉ A ÚLTIMA COLUNA
// ============================================

$sheet->setCellValue("A{$linha}", 'REPÚBLICA DE ANGOLA');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true)->setSize(10);
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;

$sheet->setCellValue("A{$linha}", 'MINISTÉRIO DA EDUCAÇÃO');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true)->setSize(10);
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;

$sheet->setCellValue("A{$linha}", 'GOVERNO DA PROVÍNCIA DO ICOLO E BENGO');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true)->setSize(10);
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;

$sheet->setCellValue("A{$linha}", 'REPARTIÇÃO MUNICIPAL DA EDUCAÇÃO DO SEQUELE');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true)->setSize(10);
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;

$sheet->setCellValue("A{$linha}", $nome_escola);
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true)->setSize(11);
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;

$sheet->setCellValue("A{$linha}", 'DISTRITO DO SEQUELE    MUNICÍPIO DO SEQUELE    PROVÍNCIA DE ICOLO E BENGO');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true)->setSize(9);
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;

$sheet->setCellValue("A{$linha}", "PAUTA DA CLASSE DE TRANSIÇÃO Nº __________ / {$ano_selecionado}");
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true)->setSize(11);
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;

$sheet->setCellValue("A{$linha}", "ANO LECTIVO: {$ano_selecionado}    PERÍODO: {$periodo}    CLASSE: {$info['classe_nome']}    TURMA: {$info['turma_nome']}    SALA: " . ($sala ?: '---'));
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true);
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;
$linha++;

// ============================================
// CABEÇALHO DA TABELA
// ============================================
$sheet->setCellValue("A{$linha}", 'Nº');
$sheet->setCellValue("B{$linha}", 'Matrícula');
$sheet->setCellValue("C{$linha}", 'Nome do Aluno');

$coluna_atual = 'D';
foreach ($disciplinas as $disc) {
    $sheet->mergeCells("{$coluna_atual}{$linha}:" . chr(ord($coluna_atual) + 3) . "{$linha}");
    $sheet->setCellValue("{$coluna_atual}{$linha}", htmlspecialchars($disc['codigo'] ?: substr($disc['nome'], 0, 3)));
    $coluna_atual = chr(ord($coluna_atual) + 4);
}
$sheet->setCellValue("{$coluna_atual}{$linha}", 'Média Geral');
$sheet->setCellValue(chr(ord($coluna_atual) + 1) . "{$linha}", 'Resultado');

$sheet->getStyle("A{$linha}:{$ultima_coluna_letra}{$linha}")->getFont()->setBold(true);
$sheet->getStyle("A{$linha}:{$ultima_coluna_letra}{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$linha}:{$ultima_coluna_letra}{$linha}")->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1e5799');
$sheet->getStyle("A{$linha}:{$ultima_coluna_letra}{$linha}")->getFont()->getColor()->setARGB('FFFFFFFF');
$linha++;

// Segunda linha do cabeçalho
$coluna_atual = 'D';
foreach ($disciplinas as $disc) {
    $sheet->setCellValue("{$coluna_atual}{$linha}", '1º');
    $sheet->setCellValue(chr(ord($coluna_atual) + 1) . "{$linha}", '2º');
    $sheet->setCellValue(chr(ord($coluna_atual) + 2) . "{$linha}", '3º');
    $sheet->setCellValue(chr(ord($coluna_atual) + 3) . "{$linha}", 'M');
    $coluna_atual = chr(ord($coluna_atual) + 4);
}
$sheet->getStyle("A{$linha}:{$ultima_coluna_letra}{$linha}")->getFont()->setBold(true);
$sheet->getStyle("A{$linha}:{$ultima_coluna_letra}{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$linha}:{$ultima_coluna_letra}{$linha}")->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1e5799');
$sheet->getStyle("A{$linha}:{$ultima_coluna_letra}{$linha}")->getFont()->getColor()->setARGB('FFFFFFFF');
$linha++;

// ============================================
// DADOS DOS ALUNOS
// ============================================
$i = 1;
foreach ($alunos as $aluno) {
    $sheet->setCellValue("A{$linha}", $i++);
    $sheet->setCellValue("B{$linha}", htmlspecialchars($aluno['matricula'] ?: '-'));
    $sheet->setCellValue("C{$linha}", htmlspecialchars($aluno['nome']));
    $sheet->getStyle("C{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    
    $coluna_atual = 'D';
    foreach ($disciplinas as $disc) {
        $disc_notas = isset($aluno['notas_por_disciplina'][$disc['id']]) ? $aluno['notas_por_disciplina'][$disc['id']] : null;
        
        for ($b = 1; $b <= 3; $b++) {
            if ($disc_notas && isset($disc_notas['bimestres'][$b]) && $disc_notas['bimestres'][$b] !== null) {
                $nota = $disc_notas['bimestres'][$b];
                $cor = getCorNota($nota, $is_classe_inferior);
                $sheet->setCellValue("{$coluna_atual}{$linha}", number_format($nota, 1));
                if ($cor == 'vermelho') {
                    $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->getColor()->setARGB('FFdc3545');
                } else {
                    $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->getColor()->setARGB('FF0066cc');
                }
                $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->setBold(true);
            } else {
                $sheet->setCellValue("{$coluna_atual}{$linha}", '0.0');
                $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->getColor()->setARGB('FFdc3545');
                $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->setBold(true);
            }
            $coluna_atual = chr(ord($coluna_atual) + 1);
        }
        
        if ($disc_notas) {
            $media = $disc_notas['media_anual'];
            $cor = getCorNota($media, $is_classe_inferior);
            $sheet->setCellValue("{$coluna_atual}{$linha}", number_format($media, 1));
            if ($cor == 'vermelho') {
                $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->getColor()->setARGB('FFdc3545');
            } else {
                $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->getColor()->setARGB('FF0066cc');
            }
            $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->setBold(true);
        } else {
            $sheet->setCellValue("{$coluna_atual}{$linha}", '0.0');
            $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->getColor()->setARGB('FFdc3545');
            $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->setBold(true);
        }
        $coluna_atual = chr(ord($coluna_atual) + 1);
    }
    
    $sheet->setCellValue("{$coluna_atual}{$linha}", number_format($aluno['media_geral'], 1));
    $sheet->getStyle("{$coluna_atual}{$linha}")->getFont()->setBold(true);
    
    $resultado_coluna = chr(ord($coluna_atual) + 1);
    $sheet->setCellValue("{$resultado_coluna}{$linha}", $aluno['resultado_geral']);
    if ($aluno['resultado_geral'] == 'Aprovado') {
        $sheet->getStyle("{$resultado_coluna}{$linha}")->getFont()->getColor()->setARGB('FF0066cc');
    } elseif ($aluno['resultado_geral'] == 'Recuperação') {
        $sheet->getStyle("{$resultado_coluna}{$linha}")->getFont()->getColor()->setARGB('FF856404');
    } else {
        $sheet->getStyle("{$resultado_coluna}{$linha}")->getFont()->getColor()->setARGB('FFdc3545');
    }
    $sheet->getStyle("{$resultado_coluna}{$linha}")->getFont()->setBold(true);
    
    $linha++;
}

// Aplicar bordas a toda a tabela
$ultima_linha = $linha - 1;
$sheet->getStyle("A1:{$ultima_coluna_letra}{$ultima_linha}")->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN);

// ============================================
// RODAPÉ
// ============================================
$linha++;
$linha++;

// Conselho de Nota
$sheet->setCellValue("A{$linha}", 'O CONSELHO DE NOTA');
$sheet->mergeCells("A{$linha}:C{$linha}");
$sheet->getStyle("A{$linha}")->getFont()->setBold(true);
$linha++;
$sheet->setCellValue("A{$linha}", '1._____________________________________________');
$sheet->mergeCells("A{$linha}:C{$linha}");
$linha++;
$sheet->setCellValue("A{$linha}", '2._____________________________________________');
$sheet->mergeCells("A{$linha}:C{$linha}");
$linha++;
$sheet->setCellValue("A{$linha}", '3._____________________________________________');
$sheet->mergeCells("A{$linha}:C{$linha}");
$linha++;
$linha++;

// Data e Assinaturas
$sheet->setCellValue("A{$linha}", "{$nome_escola}, {$dia} de {$mes} de {$ano}");
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$linha}")->getFont()->setBold(true);
$linha++;

$sheet->setCellValue("A{$linha}", '_________________________________________');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;
$sheet->setCellValue("A{$linha}", 'O(A) SUBDIRECTOR(A) PEDAGOGICO');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$linha}")->getFont()->setBold(true);
$linha++;
$linha++;

$sheet->setCellValue("A{$linha}", '_________________________________________');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$linha++;
$sheet->setCellValue("A{$linha}", 'O(A) DIRECTOR(A)');
$sheet->mergeCells("A{$linha}:{$ultima_coluna_letra}{$linha}");
$sheet->getStyle("A{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$linha}")->getFont()->setBold(true);

// Configurar cabeçalho e rodapé da página
$sheet->getHeaderFooter()->setOddHeader('&C&Página &P de &N');
$sheet->getHeaderFooter()->setOddFooter('&C&8' . $nome_escola . ' - Documento gerado eletronicamente');

// Configurar para imprimir em uma página
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

// Gerar arquivo
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="pauta_final_' . $turma_selecionada . '_' . $ano_selecionado . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>