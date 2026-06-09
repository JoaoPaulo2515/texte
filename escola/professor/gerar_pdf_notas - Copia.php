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

function getSituacaoClasse($situacao) {
    switch ($situacao) {
        case 'aprovado': return 'aprovado';
        case 'recuperacao': return 'recuperacao';
        case 'reprovado': return 'reprovado';
        default: return 'pendente';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
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
        <div>' . htmlspecialchars($disciplina['nome'] ?? 'Disciplina') . ' - ' . $bimestre . 'º Bimestre</div>
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
    </div>
    
    <table class="tabela-notas">
        <thead>
            <tr>
                <th width="5%">Nº</th>
                <th width="30%">Aluno</th>
                <th width="15%">Matrícula</th>
                <th width="10%">MAC</th>
                <th width="10%">NPT</th>
                <th width="10%">Exame</th>
                <th width="10%">Média</th>
                <th width="10%">Situação</th>
            </tr>
        </thead>
        <tbody>';

$contador = 1;
foreach ($alunos as $aluno) {
    $classe_situacao = getSituacaoClasse($aluno['situacao']);
    $texto_situacao = getSituacaoTexto($aluno['situacao']);
    
    $html .= '<tr>
        <td>' . $contador++ . '</td>
        <td>' . htmlspecialchars($aluno['nome']) . '</td>
        <td>' . htmlspecialchars($aluno['matricula']) . '</td>
        <td>' . number_format($aluno['mac'] ?? 0, 1) . '</td>
        <td>' . number_format($aluno['npt'] ?? 0, 1) . '</td>
        <td>' . number_format($aluno['exame_normal'] ?? 0, 1) . '</td>
        <td><strong>' . number_format($aluno['media_final'] ?? 0, 1) . '</strong></td>
        <td class="' . $classe_situacao . '">' . $texto_situacao . '</td>
    </tr>';
}

$html .= '
        </tbody>
        <tfoot>
            <tr style="background: #f8f9fa; font-weight: bold;">
                <td colspan="6" class="text-right">Média da Turma:</td>
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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Nome do arquivo
$nome_arquivo = 'pauta_notas_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . $bimestre . 'B_' . date('Ymd') . '.pdf';

// Enviar para download
$dompdf->stream($nome_arquivo, array('Attachment' => true));
exit;
?>