<?php
// escola/relatorios/gerar_pdf_pauta.php - Gerar PDF da Pauta de Notas

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// RECEBER PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;
$tipo_pauta = isset($_GET['tipo_pauta']) ? $_GET['tipo_pauta'] : 'todas';

// ============================================
// VALIDAÇÕES
// ============================================
if ($turma_id == 0 || $disciplina_id == 0) {
    die('Parâmetros inválidos. Turma e Disciplina são obrigatórios.');
}

// Buscar ano letivo ativo se não especificado
if ($ano_letivo_id == 0) {
    $sql_ano = "SELECT id FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':escola_id' => $escola_id]);
    $ano = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_id = $ano['id'] ?? 1;
}

// ============================================
// BUSCAR INFORMAÇÕES DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nuit FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR INFORMAÇÕES DA TURMA E DISCIPLINA
// ============================================
$sql_info = "SELECT t.nome as turma_nome, t.ano as turma_ano, t.turno, t.sala,
                    d.nome as disciplina_nome, d.codigo as disciplina_codigo
             FROM turmas t, disciplinas d
             WHERE t.id = :turma_id AND d.id = :disciplina_id";
$stmt_info = $conn->prepare($sql_info);
$stmt_info->execute([':turma_id' => $turma_id, ':disciplina_id' => $disciplina_id]);
$info = $stmt_info->fetch(PDO::FETCH_ASSOC);

if (!$info) {
    die('Turma ou Disciplina não encontrada.');
}

// ============================================
// BUSCAR ALUNOS E NOTAS
// ============================================
$sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero, e.data_nascimento,
                      n.id as nota_id, n.media_final, n.status, n.data_lancamento
               FROM estudantes e
               INNER JOIN matriculas m ON m.estudante_id = e.id
               LEFT JOIN notas n ON n.estudante_id = e.id 
                    AND n.disciplina_id = :disciplina_id 
                    AND n.bimestre = :bimestre 
                    AND n.ano_letivo_id = :ano_letivo_id
               WHERE m.turma_id = :turma_id 
                    AND m.status = 'ativa' 
                    AND m.ano_letivo = :ano_letivo_id
               ORDER BY e.nome";

$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':bimestre' => $bimestre,
    ':ano_letivo_id' => $ano_letivo_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALCULAR ESTATÍSTICAS
// ============================================
$estatisticas = [
    'total_alunos' => count($alunos),
    'total_aprovados' => 0,
    'total_exame' => 0,
    'total_reprovados' => 0,
    'total_sem_nota' => 0,
    'soma_notas' => 0,
    'media_geral' => 0,
    'maior_nota' => 0,
    'menor_nota' => 20,
    'total_com_nota' => 0
];

foreach ($alunos as $aluno) {
    $nota = $aluno['media_final'] !== null ? (float)$aluno['media_final'] : null;
    
    if ($nota !== null && $nota > 0) {
        $estatisticas['soma_notas'] += $nota;
        $estatisticas['total_com_nota']++;
        
        if ($nota >= 14) {
            $estatisticas['total_aprovados']++;
        } elseif ($nota >= 10) {
            $estatisticas['total_exame']++;
        } else {
            $estatisticas['total_reprovados']++;
        }
        
        if ($nota > $estatisticas['maior_nota']) {
            $estatisticas['maior_nota'] = $nota;
        }
        if ($nota < $estatisticas['menor_nota']) {
            $estatisticas['menor_nota'] = $nota;
        }
    } else {
        $estatisticas['total_sem_nota']++;
    }
}

$estatisticas['media_geral'] = $estatisticas['total_com_nota'] > 0 
    ? round($estatisticas['soma_notas'] / $estatisticas['total_com_nota'], 2) 
    : 0;

if ($estatisticas['menor_nota'] == 20) {
    $estatisticas['menor_nota'] = 0;
}

// ============================================
// APLICAR FILTRO DE TIPO DE PAUTA
// ============================================
$alunos_filtrados = [];
foreach ($alunos as $aluno) {
    $tem_nota = ($aluno['media_final'] !== null && $aluno['media_final'] > 0);
    
    if ($tipo_pauta == 'com_nota' && !$tem_nota) {
        continue;
    }
    if ($tipo_pauta == 'sem_nota' && $tem_nota) {
        continue;
    }
    $alunos_filtrados[] = $aluno;
}

// ============================================
// GERAR HTML PARA PDF
// ============================================
$bimestres_nomes = ['', 'PRIMEIRO bimestre', 'SEGUNDO bimestre', 'TERCEIRO bimestre'];
$bimestre_nome = $bimestres_nomes[$bimestre] ?? $bimestre . 'º bimestre';

$html = '
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Pauta de Notas - ' . htmlspecialchars($info['disciplina_nome']) . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 11px;
            padding: 20px;
            background: white;
        }
        .header {
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 15px;
        }
        .header h1 {
            font-size: 20px;
            color: #006B3E;
            margin-bottom: 5px;
        }
        .header h2 {
            font-size: 14px;
            font-weight: normal;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 10px;
            color: #666;
        }
        .info-escola {
            text-align: center;
            font-size: 10px;
            margin-bottom: 15px;
        }
        .info-pauta {
            background: #f5f5f5;
            padding: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #006B3E;
            font-size: 10px;
        }
        .info-pauta table {
            width: 100%;
            border: none;
        }
        .info-pauta td {
            padding: 3px;
            border: none;
        }
        .estatisticas {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .card-estatistica {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 8px 12px;
            min-width: 90px;
            text-align: center;
        }
        .card-estatistica .numero {
            font-size: 18px;
            font-weight: bold;
            color: #006B3E;
        }
        .card-estatistica .label {
            font-size: 9px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #006B3E;
            color: white;
            font-weight: bold;
            text-align: center;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .nota {
            text-align: center;
            font-weight: bold;
        }
        .status-aprovado {
            color: #28a745;
            font-weight: bold;
        }
        .status-exame {
            color: #ffc107;
            font-weight: bold;
        }
        .status-reprovado {
            color: #dc3545;
            font-weight: bold;
        }
        .status-sem-nota {
            color: #6c757d;
        }
        .footer {
            margin-top: 25px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .assinaturas {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        .assinatura {
            text-align: center;
            width: 200px;
        }
        .linha-assinatura {
            border-top: 1px solid #000;
            margin-top: 30px;
            padding-top: 5px;
        }
        .page-break {
            page-break-before: always;
        }
        .watermark {
            position: fixed;
            opacity: 0.1;
            font-size: 60px;
            transform: rotate(-45deg);
            top: 40%;
            left: 20%;
            width: 100%;
            text-align: center;
            z-index: -1;
        }
    </style>
</head>
<body>';

// Marca d\'água (opcional)
// $html .= '<div class="watermark">SIGE Angola</div>';

$html .= '
    <div class="header">
        <h1>' . htmlspecialchars($escola_info['nome'] ?? $escola_nome) . '</h1>
        <h2>PAUTA DE NOTAS - ' . $bimestre_nome . '</h2>
        <p>Documento gerado em: ' . date('d/m/Y H:i:s') . '</p>
    </div>
    
    <div class="info-pauta">
        <table>
            <tr>
                <td width="25%"><strong>Turma:</strong></td>
                <td width="25%">' . $info['turma_ano'] . 'ª ' . htmlspecialchars($info['turma_nome']) . '</td>
                <td width="25%"><strong>Turno:</strong></td>
                <td width="25%">' . ucfirst($info['turno']) . '</td>
            </tr>
            <tr>
                <td><strong>Disciplina:</strong></td>
                <td>' . htmlspecialchars($info['disciplina_nome']) . '</td>
                <td><strong>Código:</strong></td>
                <td>' . htmlspecialchars($info['disciplina_codigo'] ?? '---') . '</td>
            </tr>
            <tr>
                <td><strong>Sala:</strong></td>
                <td>' . htmlspecialchars($info['sala'] ?? '---') . '</td>
                <td><strong>Ano Letivo:</strong></td>
                <td>' . date('Y') . '</td>
            </tr>
        </table>
    </div>
    
    <div class="estatisticas">
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas['total_alunos'] . '</div>
            <div class="label">Total Alunos</div>
        </div>
        <div class="card-estatistica">
            <div class="numero" style="color: #28a745;">' . $estatisticas['total_aprovados'] . '</div>
            <div class="label">Aprovados (≥14)</div>
        </div>
        <div class="card-estatistica">
            <div class="numero" style="color: #ffc107;">' . $estatisticas['total_exame'] . '</div>
            <div class="label">Exame (10-13)</div>
        </div>
        <div class="card-estatistica">
            <div class="numero" style="color: #dc3545;">' . $estatisticas['total_reprovados'] . '</div>
            <div class="label">Reprovados (<10)</div>
        </div>
        <div class="card-estatistica">
            <div class="numero" style="color: #6c757d;">' . $estatisticas['total_sem_nota'] . '</div>
            <div class="label">Sem Nota</div>
        </div>
        <div class="card-estatistica">
            <div class="numero">' . number_format($estatisticas['media_geral'], 1, ',', '.') . '</div>
            <div class="label">Média Geral</div>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="12%">Matrícula</th>
                <th width="35%">Nome do Aluno</th>
                <th width="8%">Genero</th>
                <th width="12%">Nota</th>
                <th width="15%">Classificação</th>
                <th width="13%">Data Lançamento</th>
            </tr>
        </thead>
        <tbody>';

if (empty($alunos_filtrados)) {
    $html .= '<tr><td colspan="7" class="text-center" style="text-align: center;">Nenhum aluno encontrado com os filtros selecionados.</td></tr>';
} else {
    foreach ($alunos_filtrados as $index => $aluno) {
        $nota = $aluno['media_final'] !== null ? (float)$aluno['media_final'] : null;
        $nota_formatada = $nota !== null ? number_format($nota, 2, ',', '.') : '---';
        $status = '';
        $status_class = '';
        $data_lancamento = '';
        
        if ($nota !== null && $nota > 0) {
            $data_lancamento = $aluno['data_lancamento'] ? date('d/m/Y', strtotime($aluno['data_lancamento'])) : date('d/m/Y');
            
            if ($nota >= 14) {
                $status = 'APROVADO';
                $status_class = 'status-aprovado';
            } elseif ($nota >= 10) {
                $status = 'EXAME';
                $status_class = 'status-exame';
            } else {
                $status = 'REPROVADO';
                $status_class = 'status-reprovado';
            }
        } else {
            $status = 'Sem nota';
            $status_class = 'status-sem-nota';
            $data_lancamento = '---';
        }
        
        $sexo = $aluno['genero'] == 'masculino' ? 'M' : ($aluno['genero'] == 'feminino' ? 'F' : '---');
        
        $html .= '<tr>
            <td class="text-center">' . ($index + 1) . '</td>
            <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
            <td>' . htmlspecialchars($aluno['nome']) . '</td>
            <td class="text-center">' . $sexo . '</td>
            <td class="nota">' . $nota_formatada . '</td>
            <td class="' . $status_class . ' text-center">' . $status . '</td>
            <td class="text-center">' . $data_lancamento . '</td>
        </tr>';
    }
}

$html .= '
        </tbody>
        <tfoot>
            <tr style="background: #e9ecef;">
                <td colspan="4" class="text-right"><strong>RESUMO:</strong></td>
                <td colspan="3">
                    <strong>Aprovados:</strong> ' . $estatisticas['total_aprovados'] . ' | 
                    <strong>Exame:</strong> ' . $estatisticas['total_exame'] . ' | 
                    <strong>Reprovados:</strong> ' . $estatisticas['total_reprovados'] . ' | 
                    <strong>Média:</strong> ' . number_format($estatisticas['media_geral'], 2, ',', '.') . '
                </td>
            </tr>
        </tfoot>
    </table>';
    
// Tabela de Legenda
$html .= '
    <div style="margin-top: 20px; font-size: 9px;">
        <strong>LEGENDA:</strong><br>
        <span style="color: #28a745;">● APROVADO:</span> Nota igual ou superior a 14 valores<br>
        <span style="color: #ffc107;">● EXAME:</span> Nota entre 10 e 13 valores<br>
        <span style="color: #dc3545;">● REPROVADO:</span> Nota inferior a 10 valores<br>
        <span style="color: #6c757d;">● Sem nota:</span> Aluno ainda não avaliado
    </div>
    
    <div class="assinaturas">
        <div class="assinatura">
            <div class="linha-assinatura"></div>
            <p>Coordenador Pedagógico</p>
        </div>
        <div class="assinatura">
            <div class="linha-assinatura"></div>
            <p>Coordenador de Área</p>
        </div>
        <div class="assinatura">
            <div class="linha-assinatura"></div>
            <p>Professor</p>
        </div>
    </div>
    
    <div class="footer">
        <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
        <p>' . htmlspecialchars($escola_info['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola_info['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola_info['email'] ?? '') . '</p>
    </div>
</body>
</html>';

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
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo
$filename = 'pauta_' . $info['turma_ano'] . 'a_' . $info['turma_nome'] . '_' . $info['disciplina_nome'] . '_' . $bimestre . 't_' . date('Y-m-d') . '.pdf';
$filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

$dompdf->stream($filename, ["Attachment" => true]);
exit;
?>