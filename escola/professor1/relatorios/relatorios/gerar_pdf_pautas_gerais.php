<?php
// escola/professor/relatorios/gerar_pdf_pautas_gerais.php - Gerar PDF das Pautas Gerais

require_once '../includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// CARREGAR DOMPDF
// ============================================
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
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

function getStatusClass($media) {
    if ($media >= 9.5) return 'nota-alta';
    if ($media >= 5) return 'nota-media';
    if ($media > 0) return 'nota-baixa';
    return '';
}

// Gerar HTML para PDF
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Pauta Geral - ' . htmlspecialchars($disciplina['nome']) . ' - ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</title>
    <style>
        @page {
            margin: 1.5cm;
            size: A4 landscape;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 8pt;
            line-height: 1.3;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 10px;
        }
        
        .logo {
            font-size: 18pt;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 5px;
        }
        
        .escola-nome {
            font-size: 14pt;
            font-weight: bold;
            color: #1A2A6C;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .escola-info {
            font-size: 7pt;
            color: #666;
        }
        
        .titulo {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 10px 0 5px 0;
            color: #006B3E;
        }
        
        .subtitulo {
            text-align: center;
            font-size: 9pt;
            color: #555;
            margin-bottom: 15px;
        }
        
        .info-section {
            margin-bottom: 10px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 7pt;
        }
        
        .info-row {
            margin-bottom: 3px;
        }
        
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 80px;
        }
        
        .stats-section {
            margin-bottom: 10px;
        }
        
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        .stats-table td {
            padding: 5px;
            text-align: center;
            border: 1px solid #dee2e6;
            background: #f8f9fa;
        }
        
        .stats-number {
            font-size: 14pt;
            font-weight: bold;
            color: #006B3E;
        }
        
        .stats-label {
            font-size: 6pt;
            color: #666;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
            font-size: 7pt;
        }
        
        th {
            background: #006B3E;
            color: white;
            padding: 5px 3px;
            text-align: center;
            font-weight: bold;
            font-size: 7pt;
        }
        
        td {
            border: 1px solid #ddd;
            padding: 4px 3px;
            vertical-align: middle;
        }
        
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        .nota-alta {
            background-color: #d4edda !important;
        }
        .nota-media {
            background-color: #fff3cd !important;
        }
        .nota-baixa {
            background-color: #f8d7da !important;
        }
        
        .badge-aprovado {
            background: #d4edda;
            color: #155724;
            padding: 2px 6px;
            border-radius: 10px;
            display: inline-block;
            font-size: 6pt;
        }
        
        .badge-recuperacao {
            background: #fff3cd;
            color: #856404;
            padding: 2px 6px;
            border-radius: 10px;
            display: inline-block;
            font-size: 6pt;
        }
        
        .badge-reprovado {
            background: #f8d7da;
            color: #721c24;
            padding: 2px 6px;
            border-radius: 10px;
            display: inline-block;
            font-size: 6pt;
        }
        
        .badge-pendente {
            background: #e9ecef;
            color: #6c757d;
            padding: 2px 6px;
            border-radius: 10px;
            display: inline-block;
            font-size: 6pt;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 6pt;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 6px;
            background: white;
        }
        
        .legenda {
            margin-top: 10px;
            padding: 6px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 6pt;
        }
        
        .legenda-item {
            display: inline-block;
            margin-right: 12px;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="logo">SIGE ANGOLA</div>
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SISTEMA INTEGRADO DE GESTÃO ESCOLAR')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . '</div>
    <div class="escola-info">Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</div>
</div>

<div class="titulo">PAUTA GERAL DE NOTAS</div>
<div class="subtitulo">
    ' . $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . htmlspecialchars($disciplina['nome']) . ' | ANO LETIVO ' . $ano_letivo_ano . '
</div>

<div class="info-section">
    <div class="info-row"><span class="info-label">Turma:</span> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</div>
    <div class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma['turno']) . '</div>
    <div class="info-row"><span class="info-label">Sala:</span> ' . ($turma['sala'] ?: 'Não definida') . '</div>
    <div class="info-row"><span class="info-label">Disciplina:</span> ' . htmlspecialchars($disciplina['nome']) . ' (' . htmlspecialchars($disciplina['codigo'] ?? '-') . ')</div>
    <div class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($professor['professor_nome']) . '</div>
    <div class="info-row"><span class="info-label">Data Emissão:</span> ' . date('d/m/Y H:i:s') . '</div>
    <div class="info-row"><span class="info-label">Regras:</span> ' . ($is_ensino_fundamental ? 'Escala 0-10' : 'Escala 0-20') . 
    ($is_classe_exame ? ' | Classe de Exame (' . $classe_ano . 'ª)' : '') . 
    ($is_disciplina_lingua ? ' | Disciplina de Língua' : '') . '</div>
</div>

<div class="stats-section">
    <table class="stats-table">
        <tr>
            <td><div class="stats-number">' . $total_alunos . '</div><div class="stats-label">Total Alunos</div></td>
            <td><div class="stats-number" style="color: #28a745;">' . $total_aprovados . '</div><div class="stats-label">Aprovados (' . $percentual_aprovacao . '%)</div></td>
            <td><div class="stats-number" style="color: #ffc107;">' . $total_recuperacao . '</div><div class="stats-label">Recuperação</div></td>
            <td><div class="stats-number" style="color: #dc3545;">' . $total_reprovados . '</div><div class="stats-label">Reprovados</div></td>
            <td><div class="stats-number">' . $media_geral_anual . '</div><div class="stats-label">Média Anual</div></td>
        </tr>
    </table>
</div>

<table>
    <thead>
        <tr>
            <th rowspan="2" width="4%">#</th>
            <th rowspan="2" width="20%">Aluno</th>
            <th rowspan="2" width="8%">Matrícula</th>
            <th colspan="3">1º Bimestre</th>
            <th colspan="3">2º Bimestre</th>';
            
if ($is_classe_exame) {
    if ($is_disciplina_lingua) {
        $html .= '<th colspan="5">3º Bimestre</th>';
    } else {
        $html .= '<th colspan="4">3º Bimestre</th>';
    }
} else {
    $html .= '<th colspan="3">3º Bimestre</th>';
}

$html .= '
            <th rowspan="2" width="7%">Média<br>Anual</th>
            <th rowspan="2" width="10%">Situação</th>
        </tr>
        <tr>
            <th>MAC</th><th>NPT</th><th>Média</th>
            <th>MAC</th><th>NPT</th><th>Média</th>';
            
if ($is_classe_exame) {
    if ($is_disciplina_lingua) {
        $html .= '<th>MAC</th><th>NPT</th><th>Exame Oral</th><th>Exame Escrito</th><th>Média</th>';
    } else {
        $html .= '<th>MAC</th><th>NPT</th><th>Exame Normal</th><th>Média</th>';
    }
} else {
    $html .= '<th>MAC</th><th>NPT</th><th>Média</th>';
}

$html .= '
        </tr>
    </thead>
    <tbody>
';

$contador = 1;
foreach ($alunos as $aluno) {
    $b1 = $aluno['bimestres'][1];
    $b2 = $aluno['bimestres'][2];
    $b3 = $aluno['bimestres'][3];
    
    $situacao_final = $aluno['situacao_final'];
    $badge_class = '';
    switch ($situacao_final) {
        case 'aprovado': $badge_class = 'badge-aprovado'; break;
        case 'recuperacao': $badge_class = 'badge-recuperacao'; break;
        case 'reprovado': $badge_class = 'badge-reprovado'; break;
        default: $badge_class = 'badge-pendente';
    }
    $situacao_texto = getSituacaoTexto($situacao_final);
    
    $html .= '
        <tr>
            <td class="text-center">' . $contador++ . '</td>
            <td class="text-left"><strong>' . htmlspecialchars($aluno['nome']) . '</strong></td>
            <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
            <!-- 1º Bimestre -->
            <td class="text-center ' . getStatusClass($b1['media_final']) . '">' . getNotaFormatada($b1['mac']) . '</td>
            <td class="text-center ' . getStatusClass($b1['media_final']) . '">' . getNotaFormatada($b1['npt']) . '</td>
            <td class="text-center ' . getStatusClass($b1['media_final']) . '"><strong>' . getNotaFormatada($b1['media_final']) . '</strong></td>
            <!-- 2º Bimestre -->
            <td class="text-center ' . getStatusClass($b2['media_final']) . '">' . getNotaFormatada($b2['mac']) . '</td>
            <td class="text-center ' . getStatusClass($b2['media_final']) . '">' . getNotaFormatada($b2['npt']) . '</td>
            <td class="text-center ' . getStatusClass($b2['media_final']) . '"><strong>' . getNotaFormatada($b2['media_final']) . '</strong></td>
            <!-- 3º Bimestre -->
            <td class="text-center ' . getStatusClass($b3['media_final']) . '">' . getNotaFormatada($b3['mac']) . '</td>
            <td class="text-center ' . getStatusClass($b3['media_final']) . '">' . getNotaFormatada($b3['npt']) . '</td>';
            
    if ($is_classe_exame) {
        if ($is_disciplina_lingua) {
            $html .= '<td class="text-center ' . getStatusClass($b3['media_final']) . '">' . getNotaFormatada($b3['exame_oral']) . '</td>
            <td class="text-center ' . getStatusClass($b3['media_final']) . '">' . getNotaFormatada($b3['exame_escrito']) . '</td>';
        } else {
            $html .= '<td class="text-center ' . getStatusClass($b3['media_final']) . '">' . getNotaFormatada($b3['exame_normal']) . '</td>';
        }
    }
    
    $html .= '<td class="text-center ' . getStatusClass($b3['media_final']) . '"><strong>' . getNotaFormatada($b3['media_final']) . '</strong></td>
            <!-- Média Anual -->
            <td class="text-center"><strong>' . number_format($aluno['media_anual'], 1) . '</strong></td>
            <!-- Situação -->
            <td class="text-center"><span class="' . $badge_class . '">' . $situacao_texto . '</span></td>
        </tr>
    ';
}

$html .= '
    </tbody>
</table>

<div class="legenda">
    <strong>Legenda:</strong>
    <span class="legenda-item"><span style="background:#d4edda; padding:2px 6px;">Verde</span> - Nota ≥ 9.5</span>
    <span class="legenda-item"><span style="background:#fff3cd; padding:2px 6px;">Amarelo</span> - Nota 5.0 - 9.4</span>
    <span class="legenda-item"><span style="background:#f8d7da; padding:2px 6px;">Vermelho</span> - Nota &lt; 5.0</span>
    <span class="legenda-item"><span class="badge-aprovado">Aprovado</span> - Média ' . ($is_ensino_fundamental ? '> 5' : '> 10') . '</span>
    <span class="legenda-item"><span class="badge-recuperacao">Recuperação</span> - Média ' . ($is_ensino_fundamental ? '= 5' : '= 10') . '</span>
    <span class="legenda-item"><span class="badge-reprovado">Reprovado</span> - Média ' . ($is_ensino_fundamental ? '< 5' : '< 10') . '</span>
';

if ($is_classe_exame) {
    $html .= '<br><span class="legenda-item"><strong>Regra Classe Exame (3º Bimestre):</strong> Média = (MAC + NPT)/2 × 0.4 + Exame × 0.6</span>';
    if ($is_disciplina_lingua) {
        $html .= '<span class="legenda-item"> | Exame = (Oral + Escrito)/2</span>';
    }
}

$html .= '
</div>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Documento emitido eletronicamente em ' . date('d/m/Y H:i:s') . '<br>
    ' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '
</div>

</body>
</html>
';

// ============================================
// CONFIGURAR E GERAR PDF
// ============================================
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $options->set('chroot', 'C:/xampp/htdocs');
}

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$nome_arquivo = 'pauta_geral_' . $turma['ano'] . 'ª_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . date('Ymd_His') . '.pdf';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>