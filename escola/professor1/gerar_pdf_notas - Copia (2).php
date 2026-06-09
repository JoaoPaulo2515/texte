<?php
// escola/professor/gerar_pdf_notas.php - Gerar PDF das Notas da Turma

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

// Verificar se é disciplina de língua (Português, Inglês, Francês, etc.)
$disciplinas_lingua = ['Português', 'Inglês', 'Francês', 'Espanhol', 'Língua Portuguesa', 'Língua Inglesa'];
$is_disciplina_lingua = false;
foreach ($disciplinas_lingua as $lingua) {
    if (stripos($disciplina['nome'], $lingua) !== false) {
        $is_disciplina_lingua = true;
        break;
    }
}

// Verificar se é classe de exame (6ª e 9ª classe)
$is_classe_exame = ($turma['ano'] == 6 || $turma['ano'] == 9);

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
// BUSCAR TODOS OS BIMESTRES PARA MÉDIA FINAL (CLASSE DE EXAME)
// ============================================
$todas_notas = [];
if ($is_classe_exame && $bimestre == 3) {
    $sql_todas_notas = "
        SELECT 
            n.estudante_id,
            n.bimestre,
            n.mac,
            n.npt,
            n.exame_normal,
            n.exame_oral,
            n.exame_escrito,
            n.media_final
        FROM notas n
        WHERE n.disciplina_id = :disciplina_id 
        AND n.ano_letivo_id = :ano_letivo_id
    ";
    $stmt_todas = $conn->prepare($sql_todas_notas);
    $stmt_todas->execute([
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    while ($row = $stmt_todas->fetch(PDO::FETCH_ASSOC)) {
        $todas_notas[$row['estudante_id']][$row['bimestre']] = $row;
    }
}

// ============================================
// FUNÇÃO PARA CALCULAR MÉDIA CONFORME REGRAS
// ============================================
function calcularMediaRegras($aluno, $bimestre, $is_classe_exame, $is_disciplina_lingua, $todas_notas = []) {
    $mac = floatval($aluno['mac'] ?? 0);
    $npt = floatval($aluno['npt'] ?? 0);
    $exame_normal = floatval($aluno['exame_normal'] ?? 0);
    $exame_oral = floatval($aluno['exame_oral'] ?? 0);
    $exame_escrito = floatval($aluno['exame_escrito'] ?? 0);
    
    $media_parcial = ($mac + $npt) / 2;
    
    // Para 1º e 2º bimestre: apenas MAC e NPT
    if ($bimestre == 1 || $bimestre == 2) {
        $media_final = $media_parcial;
        $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
        return ['media' => $media_final, 'status' => $status, 'detalhes' => "MAC({$mac}) + NPT({$npt}) = {$media_parcial}"];
    }
    
    // Para 3º bimestre
    if ($bimestre == 3) {
        // Disciplina de língua: exame oral e escrito combinados
        if ($is_disciplina_lingua) {
            $media_exame = ($exame_oral + $exame_escrito) / 2;
            $media_final = $media_exame > 0 ? ($media_parcial + $media_exame) / 2 : $media_parcial;
            $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            return ['media' => $media_final, 'status' => $status, 'detalhes' => "Oral({$exame_oral}) + Escrito({$exame_escrito}) = {$media_exame} | Media Parcial({$media_parcial}) = {$media_final}"];
        }
        
        // Classe de exame (6ª e 9ª)
        if ($is_classe_exame) {
            // Exame vale 60% da média final
            $media_exame = $exame_normal;
            $media_final = $media_exame > 0 ? ($media_parcial * 0.4) + ($media_exame * 0.6) : $media_parcial;
            $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            return ['media' => $media_final, 'status' => $status, 'detalhes' => "40% Parcial({$media_parcial}) + 60% Exame({$media_exame}) = {$media_final}"];
        }
        
        // Classes normais com exame
        if ($exame_normal > 0) {
            $media_final = ($media_parcial + $exame_normal) / 2;
            $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            return ['media' => $media_final, 'status' => $status, 'detalhes' => "MAC+NPT({$media_parcial}) + Exame({$exame_normal}) = {$media_final}"];
        }
        
        // Sem exame
        $media_final = $media_parcial;
        $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
        return ['media' => $media_final, 'status' => $status, 'detalhes' => "Apenas MAC+NPT = {$media_parcial}"];
    }
    
    return ['media' => 0, 'status' => 'pendente', 'detalhes' => ''];
}

// ============================================
// FUNÇÃO PARA CALCULAR MÉDIA FINAL DO ANO (CLASSE DE EXAME)
// ============================================
function calcularMediaFinalAno($estudante_id, $todas_notas, $is_disciplina_lingua) {
    $medias_bimestres = [];
    
    for ($b = 1; $b <= 3; $b++) {
        if (isset($todas_notas[$estudante_id][$b])) {
            $nota = $todas_notas[$estudante_id][$b];
            $mac = floatval($nota['mac'] ?? 0);
            $npt = floatval($nota['npt'] ?? 0);
            $exame_normal = floatval($nota['exame_normal'] ?? 0);
            $exame_oral = floatval($nota['exame_oral'] ?? 0);
            $exame_escrito = floatval($nota['exame_escrito'] ?? 0);
            
            $media_parcial = ($mac + $npt) / 2;
            
            if ($is_disciplina_lingua) {
                $media_exame = ($exame_oral + $exame_escrito) / 2;
                $media_bimestre = $media_exame > 0 ? ($media_parcial + $media_exame) / 2 : $media_parcial;
            } else {
                $media_bimestre = $exame_normal > 0 ? ($media_parcial + $exame_normal) / 2 : $media_parcial;
            }
            
            $medias_bimestres[] = $media_bimestre;
        }
    }
    
    if (count($medias_bimestres) > 0) {
        $media_anual = array_sum($medias_bimestres) / count($medias_bimestres);
        $status = $media_anual >= 10 ? 'aprovado' : ($media_anual >= 7 ? 'recuperacao' : 'reprovado');
        return ['media' => round($media_anual, 1), 'status' => $status];
    }
    
    return ['media' => 0, 'status' => 'pendente'];
}

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

function getSituacaoClasse($situacao) {
    switch ($situacao) {
        case 'aprovado': return 'aprovado';
        case 'recuperacao': return 'recuperacao';
        case 'reprovado': return 'reprovado';
        default: return 'pendente';
    }
}

function formatarDataExtenso($data) {
    if (empty($data)) $data = date('Y-m-d');
    
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    
    $timestamp = strtotime($data);
    $dia = date('d', $timestamp);
    $mes = (int)date('m', $timestamp);
    $ano = date('Y', $timestamp);
    
    return $dia . ' de ' . $meses[$mes] . ' de ' . $ano;
}

// Calcular estatísticas
$total_alunos = count($alunos);
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_notas = 0;

// Processar alunos para exibição
$alunos_processados = [];
foreach ($alunos as $aluno) {
    if ($is_classe_exame && $bimestre == 3) {
        $resultado = calcularMediaFinalAno($aluno['id'], $todas_notas, $is_disciplina_lingua);
        $media_final = $resultado['media'];
        $situacao = $resultado['status'];
    } else {
        $resultado = calcularMediaRegras($aluno, $bimestre, $is_classe_exame, $is_disciplina_lingua, $todas_notas);
        $media_final = $resultado['media'];
        $situacao = $resultado['status'];
    }
    
    $alunos_processados[] = [
        'id' => $aluno['id'],
        'nome' => $aluno['nome'],
        'matricula' => $aluno['matricula'],
        'mac' => $aluno['mac'],
        'npt' => $aluno['npt'],
        'exame_normal' => $aluno['exame_normal'],
        'exame_oral' => $aluno['exame_oral'],
        'exame_escrito' => $aluno['exame_escrito'],
        'media_final' => $media_final,
        'situacao' => $situacao
    ];
    
    if ($situacao == 'aprovado') $total_aprovados++;
    elseif ($situacao == 'recuperacao') $total_recuperacao++;
    elseif ($situacao == 'reprovado') $total_reprovados++;
    
    if ($media_final > 0) $soma_notas += $media_final;
}

$media_geral = $total_alunos > 0 ? round($soma_notas / $total_alunos, 1) : 0;

// ============================================
// GERAR PDF
// ============================================

require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

$html = '<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Pauta de Notas - ' . htmlspecialchars($turma['nome']) . '</title>
    <style>
        @page {
            margin: 2cm;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            margin: 0;
            padding: 0;
            background: white;
            font-size: 11px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 15px;
        }
        .logo-escola {
            max-height: 60px;
            max-width: 150px;
            margin-bottom: 10px;
        }
        .nome-escola {
            font-size: 16px;
            font-weight: bold;
            color: #006B3E;
            margin: 5px 0;
            text-transform: uppercase;
        }
        .titulo {
            font-size: 18px;
            font-weight: bold;
            margin: 15px 0 5px 0;
            text-transform: uppercase;
        }
        .subtitulo {
            font-size: 12px;
            color: #666;
            margin-bottom: 10px;
        }
        .info-turma {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin: 15px 0;
            font-size: 10px;
        }
        .info-turma table {
            width: 100%;
        }
        .info-turma td {
            padding: 3px;
        }
        .info-turma td:first-child {
            width: 120px;
            font-weight: bold;
        }
        .tabela-notas {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .tabela-notas th {
            background: #006B3E;
            color: white;
            padding: 8px;
            text-align: center;
            border: 1px solid #ddd;
            font-size: 10px;
        }
        .tabela-notas td {
            padding: 6px;
            border: 1px solid #ddd;
            text-align: center;
            font-size: 10px;
        }
        .tabela-notas td:first-child {
            text-align: left;
        }
        .tabela-notas td:nth-child(2) {
            text-align: left;
        }
        .aprovado { color: #28a745; font-weight: bold; }
        .recuperacao { color: #ffc107; font-weight: bold; }
        .reprovado { color: #dc3545; font-weight: bold; }
        .pendente { color: #6c757d; }
        .resumo {
            margin-top: 20px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 10px;
        }
        .assinatura {
            margin-top: 30px;
            text-align: center;
        }
        .assinatura-linha {
            width: 200px;
            border-top: 1px solid #000;
            margin: 0 auto 10px auto;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .bold { font-weight: bold; }
        .info-regras {
            background: #e8f5e9;
            border-left: 4px solid #006B3E;
            padding: 8px;
            margin-bottom: 15px;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <div class="header">';

if (!empty($escola['logo']) && file_exists('../../uploads/escolas/logos/' . $escola['logo'])) {
    $html .= '<img src="../../uploads/escolas/logos/' . $escola['logo'] . '" class="logo-escola">';
}

$html .= '
        <div class="nome-escola">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'ESCOLA')) . '</div>
        <div class="titulo">PAUTA DE NOTAS</div>
        <div class="subtitulo">' . htmlspecialchars($disciplina['nome'] ?? 'Disciplina') . ' - ' . $bimestre . 'º Bimestre</div>
    </div>
    
    <div class="info-turma">
        <table>
            <tr><td>Turma:</td><td><strong>' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</strong></td>
                <td>Turno:</td><td>' . ucfirst(htmlspecialchars($turma['turno'])) . '</td>
            </tr>
            <tr><td>Ano Letivo:</td><td>' . htmlspecialchars($ano_letivo['ano'] ?? date('Y')) . '</td>
                <td>Sala:</td><td>' . htmlspecialchars($turma['sala'] ?? 'Não definida') . '</td>
            </tr>
            <tr><td>Data Emissão:</td><td>' . formatarDataExtenso(date('Y-m-d')) . '</td>
                <td>Professor:</td><td>' . htmlspecialchars($professor['professor_nome']) . '</td>
            </tr>
        </table>
    </div>';

// Informações das regras de avaliação
$html .= '<div class="info-regras">';
if ($is_classe_exame && $bimestre == 3) {
    $html .= '<i class="fas fa-calculator"></i> <strong>Regras de Avaliação (Classe de Exame - 6ª/9ª):</strong> Média Final = (Média dos 3 Bimestres × 40%) + (Exame × 60%)';
} elseif ($is_classe_exame) {
    $html .= '<i class="fas fa-info-circle"></i> <strong>Classe de Exame:</strong> Este é o ' . $bimestre . 'º Bimestre. A média final do ano será calculada após o 3º bimestre.';
} elseif ($is_disciplina_lingua && $bimestre == 3) {
    $html .= '<i class="fas fa-language"></i> <strong>Disciplina de Língua:</strong> A nota final é composta por: (MAC + NPT)/2 + (Exame Oral + Exame Escrito)/2';
} elseif ($bimestre == 3) {
    $html .= '<i class="fas fa-calculator"></i> <strong>Regras de Avaliação:</strong> Média Final = (MAC + NPT)/2 + Exame (quando aplicável)';
} else {
    $html .= '<i class="fas fa-info-circle"></i> <strong>'.$bimestre.'º Bimestre:</strong> Nota = (MAC + NPT)/2';
}
$html .= '</div>';

// Montar cabeçalho da tabela conforme o bimestre e tipo
$html .= '<table class="tabela-notas">
        <thead>
            <tr>
                <th width="4%">Nº</th>
                <th width="25%">Aluno</th>
                <th width="12%">Matrícula</th>
                <th width="8%">MAC</th>
                <th width="8%">NPT</th>';
                
if ($bimestre == 3) {
    if ($is_disciplina_lingua) {
        $html .= '<th width="8%">Exame Oral</th>
                  <th width="8%">Exame Escrito</th>
                  <th width="8%">Média Exame</th>';
    } else {
        $html .= '<th width="10%">Exame</th>';
    }
}
                
$html .= '      <th width="8%">Média</th>
                <th width="10%">Situação</th>
            </tr>
        </thead>
        <tbody>';

$contador = 1;
foreach ($alunos_processados as $aluno) {
    $classe_situacao = getSituacaoClasse($aluno['situacao']);
    $texto_situacao = getSituacaoTexto($aluno['situacao']);
    
    // Calcular média do exame para línguas
    $media_exame = 0;
    if ($is_disciplina_lingua && $bimestre == 3) {
        $media_exame = (floatval($aluno['exame_oral'] ?? 0) + floatval($aluno['exame_escrito'] ?? 0)) / 2;
    }
    
    $html .= '<tr>
        <td>' . $contador++ . '</td>
        <td>' . htmlspecialchars($aluno['nome']) . '</td>
        <td>' . htmlspecialchars($aluno['matricula']) . '</td>
        <td>' . number_format($aluno['mac'] ?? 0, 1) . '</td>
        <td>' . number_format($aluno['npt'] ?? 0, 1) . '</td>';
    
    if ($bimestre == 3) {
        if ($is_disciplina_lingua) {
            $html .= '<td>' . number_format($aluno['exame_oral'] ?? 0, 1) . '</td>
                      <td>' . number_format($aluno['exame_escrito'] ?? 0, 1) . '</td>
                      <td>' . number_format($media_exame, 1) . '</td>';
        } else {
            $html .= '<td>' . number_format($aluno['exame_normal'] ?? 0, 1) . '</td>';
        }
    }
    
    $html .= '   <td><strong>' . number_format($aluno['media_final'] ?? 0, 1) . '</strong></td>
                <td class="' . $classe_situacao . '">' . $texto_situacao . '</td>
            </tr>';
}

$html .= '
        </tbody>
        <tfoot>
            <tr style="background: #f8f9fa; font-weight: bold;">
                <td colspan="' . ($bimestre == 3 ? ($is_disciplina_lingua ? 8 : 7) : 5) . '" class="text-right">Média da Turma:</td>
                <td colspan="2">' . number_format($media_geral, 1) . '</td>
            </tr>
        </tfoot>
    </table>';
    
$html .= '
    <div class="resumo">
        <strong>RESUMO:</strong><br>
        Total de Alunos: ' . $total_alunos . '<br>
        Aprovados: ' . $total_aprovados . ' (' . ($total_alunos > 0 ? round($total_aprovados / $total_alunos * 100, 1) : 0) . '%)<br>
        Recuperação: ' . $total_recuperacao . ' (' . ($total_alunos > 0 ? round($total_recuperacao / $total_alunos * 100, 1) : 0) . '%)<br>
        Reprovados: ' . $total_reprovados . ' (' . ($total_alunos > 0 ? round($total_reprovados / $total_alunos * 100, 1) : 0) . '%)<br>
        Média Geral: ' . number_format($media_geral, 1) . ' valores
    </div>
    
    <div class="assinatura">
        <div class="assinatura-linha"></div>
        <div>' . htmlspecialchars($professor['professor_nome']) . '</div>
        <div>Professor(a) Responsável</div>
    </div>
    
    <div class="footer">
        Documento emitido eletronicamente - ' . formatarDataExtenso(date('Y-m-d')) . '<br>
        ' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '
    </div>
</body>
</html>';

// Gerar PDF
$dompdf->loadHtml($html);
//$dompdf->setPaper('A4', 'landscape');
// gerar_pdf_notas_portrait.php
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo
$nome_arquivo = 'pauta_notas_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . $bimestre . 'B_' . date('Ymd') . '.pdf';

// Enviar para download
$dompdf->stream($nome_arquivo, array('Attachment' => true));
exit;
?>