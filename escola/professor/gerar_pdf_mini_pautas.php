<?php
// escola/professor/relatorios/gerar_pdf_mini_pautas.php - Gerar PDF das Mini Pautas

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// CARREGAR DOMPDF
// ============================================
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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
$nota_minima_verde = $is_ensino_fundamental ? 4.5 : 10;

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
        AND n.bimestre = :bimestre 
        AND n.ano_letivo_id = :ano_letivo_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";

$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':bimestre' => $bimestre,
    ':ano_letivo_id' => $ano_letivo_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÃO PARA CALCULAR MÉDIA DO 3º BIMESTRE
// ============================================
function calcularMediaFinal3BimestrePDF($aluno, $is_classe_exame, $is_disciplina_lingua) {
    $mac = floatval($aluno['mac'] ?? 0);
    $npt = floatval($aluno['npt'] ?? 0);
    $exame_normal = floatval($aluno['exame_normal'] ?? 0);
    $exame_recurso = floatval($aluno['exame_recurso'] ?? 0);
    $exame_oral = floatval($aluno['exame_oral'] ?? 0);
    $exame_escrito = floatval($aluno['exame_escrito'] ?? 0);
    
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

// Calcular médias finais
foreach ($alunos as &$aluno) {
    if ($bimestre == 3) {
        $media_calculada = calcularMediaFinal3BimestrePDF($aluno, $is_classe_exame, $is_disciplina_lingua);
        if ($aluno['media_final'] == 0 || $aluno['media_final'] == null) {
            $aluno['media_final'] = $media_calculada;
            
            if ($media_calculada > $limite_aprovacao) {
                $aluno['situacao'] = 'aprovado';
            } elseif ($media_calculada == $limite_aprovacao && $media_calculada > 0) {
                $aluno['situacao'] = 'recuperacao';
            } elseif ($media_calculada > 0 && $media_calculada < $limite_aprovacao) {
                $aluno['situacao'] = 'reprovado';
            }
        }
    }
}

// ============================================
// ESTATÍSTICAS
// ============================================
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
$percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÃO PARA COR DA NOTA BASEADA NA ESCALA
// ============================================
function getCorNota($nota, $is_ensino_fundamental) {
    if ($nota <= 0) return '#6c757d'; // Cinza para sem nota
    
    if ($is_ensino_fundamental) {
        // Ensino Fundamental (1ª à 6ª Classe) - Escala 0-10
        if ($nota >= 4.5) {
            return '#28a745'; // Verde para notas positivas (>= 4.5)
        } else {
            return '#dc3545'; // Vermelho para notas negativas (< 4.5)
        }
    } else {
        // Ensino Secundário (7ª à 12ª Classe) - Escala 0-20
        if ($nota >= 9.5) {
            return '#28a745'; // Verde para notas positivas (>= 9.5)
        } else {
            return '#dc3545'; // Vermelho para notas negativas (< 9.5)
        }
    }
}

function getCorNotaPorcentagem($porcentagem) {
    if ($porcentagem >= 50) {
        return '#28a745'; // Verde para >= 50%
    } else {
        return '#dc3545'; // Vermelho para < 50%
    }
}

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

function formatarNotaPDF($valor) {
    if ($valor === null || $valor === '') return '-';
    return number_format(floatval($valor), 1);
}

// ============================================
// GERAR HTML PARA PDF (OTIMIZADO)
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Mini Pauta - ' . htmlspecialchars($disciplina['nome']) . ' - ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</title>
    <style>
        @page {
            margin: 1.2cm 0.8cm;
            size: A4 portrait;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 8pt;
            line-height: 1.2;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 8px;
            border-bottom: 1.5px solid #006B3E;
            padding-bottom: 5px;
        }
        
        .escola-nome {
            font-size: 12pt;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        
        .escola-info {
            font-size: 6.5pt;
            color: #666;
        }
        
        .titulo {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 5px 0 2px 0;
            color: #006B3E;
        }
        
        .subtitulo {
            text-align: center;
            font-size: 8pt;
            color: #555;
            margin-bottom: 8px;
        }
        
        .info-section {
            margin-bottom: 8px;
            padding: 5px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 7pt;
            text-align: center;
        }
        
        .info-row {
            display: inline-block;
            margin: 0 8px;
        }
        
        .info-label {
            font-weight: bold;
        }
        
        /* Estatísticas em linha única */
        .stats-line {
            text-align: center;
            margin-bottom: 8px;
            white-space: nowrap;
        }
        
        .stats-box {
            display: inline-block;
            width: 60px;
            margin: 0 2px;
            padding: 3px 2px;
            background: #f8f9fa;
            text-align: center;
            border-radius: 3px;
            border: 0.5px solid #e0e0e0;
        }
        
        .stats-number {
            font-size: 9pt;
            font-weight: bold;
            line-height: 1.1;
        }
        
        .stats-label {
            font-size: 5.5pt;
            color: #666;
            margin-top: 1px;
            line-height: 1;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 5px 0;
            font-size: 6.5pt;
        }
        
        th {
            background: #006B3E;
            color: white;
            padding: 4px 2px;
            text-align: center;
            font-weight: bold;
            font-size: 6.5pt;
        }
        
        td {
            border: 0.5px solid #ddd;
            padding: 3px 2px;
            vertical-align: middle;
            font-size: 6.5pt;
        }
        
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        /* Cores das notas */
        .nota-positiva {
            color: #28a745;
            font-weight: bold;
        }
        
        .nota-negativa {
            color: #dc3545;
            font-weight: bold;
        }
        
        .nota-neutral {
            color: #6c757d;
        }
        
        .badge-aprovado {
            background: #d4edda;
            color: #155724;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 6pt;
        }
        
        .badge-recuperacao {
            background: #fff3cd;
            color: #856404;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 6pt;
        }
        
        .badge-reprovado {
            background: #f8d7da;
            color: #721c24;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 6pt;
        }
        
        .badge-pendente {
            background: #e9ecef;
            color: #6c757d;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 6pt;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 5.5pt;
            color: #999;
            border-top: 0.5px solid #ddd;
            padding-top: 4px;
            background: white;
        }
        
        .assinatura {
            margin-top: 12px;
            text-align: center;
        }
        
        .assinatura-linha {
            display: inline-block;
            width: 160px;
            border-top: 0.5px solid #000;
            margin-top: 15px;
        }
        
        .assinatura-texto {
            font-size: 7pt;
            margin-top: 2px;
        }
        
        .legenda-cores {
            font-size: 5.5pt;
            text-align: center;
            margin-top: 5px;
            padding: 3px;
            background: #f8f9fa;
            border-radius: 3px;
        }
        
        .legenda-cores span {
            display: inline-block;
            margin: 0 10px;
        }
        
        .cor-verde {
            color: #28a745;
            font-weight: bold;
        }
        
        .cor-vermelha {
            color: #dc3545;
            font-weight: bold;
        }
        
        .page-break {
            page-break-before: avoid;
            page-break-inside: avoid;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | NIF: ' . htmlspecialchars($escola['nif'] ?? '') . '</div>
</div>

<div class="titulo">MINI PAUTA DE NOTAS</div>
<div class="subtitulo">
    ' . $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . htmlspecialchars($disciplina['nome']) . ' | ' . $bimestre . 'º BIMESTRE
</div>

<div class="info-section">
    <span class="info-row"><span class="info-label">Turma:</span> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</span>
    <span class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma['turno']) . '</span>
    <span class="info-row"><span class="info-label">Sala:</span> ' . ($turma['sala'] ?: '-') . '</span>
    <span class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($professor['professor_nome']) . '</span>
    <span class="info-row"><span class="info-label">Data:</span> ' . date('d/m/Y') . '</span>
</div>

<!-- Estatísticas em linha única -->
<div class="stats-line">
    <div class="stats-box"><div class="stats-number">' . $total_alunos . '</div><div class="stats-label">Total</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #28a745;">' . $total_aprovados . '</div><div class="stats-label">Aprov</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #ffc107;">' . $total_recuperacao . '</div><div class="stats-label">Recup</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #dc3545;">' . $total_reprovados . '</div><div class="stats-label">Reprov</div></div>
    <div class="stats-box"><div class="stats-number">' . $media_geral . '</div><div class="stats-label">Média</div></div>
    <div class="stats-box"><div class="stats-number">' . $percentual_aprovacao . '%</div><div class="stats-label">Aprov%</div></div>
</div>

<!-- Legenda de Cores -->
<div class="legenda-cores">
    <span><span class="cor-verde">●</span> Nota positiva (' . ($is_ensino_fundamental ? '≥ 4.5' : '≥ 9.5') . ')</span>
    <span><span class="cor-vermelha">●</span> Nota negativa (' . ($is_ensino_fundamental ? '< 4.5' : '< 9.5') . ')</span>
    <span><span class="badge-aprovado">Aprovado</span> ≥ ' . $limite_aprovacao . '</span>
    <span><span class="badge-recuperacao">Recuperação</span> = ' . $limite_aprovacao . '</span>
    <span><span class="badge-reprovado">Reprovado</span> < ' . $limite_aprovacao . '</span>
</div>

<table>
    <thead>
        <tr>
            <th width="4%">#</th>
            <th width="32%">Aluno</th>
            <th width="10%">Matrícula</th>
            <th width="7%">MAC</th>';

// NPT: só aparece para classes normais
if ($bimestre != 3 || ($bimestre == 3 && !$is_classe_exame)) {
    $html .= '<th width="7%">NPT</th>';
}

// Exames para classes de exame
if ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua) {
    $html .= '<th width="9%">Exame Normal</th>
              <th width="9%">Exame Recurso</th>';
} elseif ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua) {
    $html .= '<th width="7%">Exame Oral</th>
              <th width="7%">Exame Escrito</th>
              <th width="7%">Exame Recurso</th>';
} elseif ($bimestre == 3) {
    $html .= '<th width="7%">Exame</th>';
}

$html .= '<th width="7%">Média</th>
            <th width="10%">Situação</th>
        </tr>
    </thead>
    <tbody>';

foreach ($alunos as $index => $aluno) {
    $situacao = $aluno['situacao'] ?? 'pendente';
    $badge_class = '';
    switch ($situacao) {
        case 'aprovado': $badge_class = 'badge-aprovado'; break;
        case 'recuperacao': $badge_class = 'badge-recuperacao'; break;
        case 'reprovado': $badge_class = 'badge-reprovado'; break;
        default: $badge_class = 'badge-pendente';
    }
    $situacao_texto = getSituacaoTexto($situacao);
    $media = $aluno['media_final'] ?? 0;
    
    // Cor da média baseada na escala
    $cor_media = getCorNota($media, $is_ensino_fundamental);
    $nota_class = ($cor_media == '#28a745') ? 'nota-positiva' : (($cor_media == '#dc3545') ? 'nota-negativa' : 'nota-neutral');
    
    $html .= '
        <tr>
            <td class="text-center">' . ($index + 1) . '</td>
            <td class="text-left">' . htmlspecialchars(substr($aluno['nome'], 0, 35)) . '</td>
            <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
            <td class="text-center ' . $nota_class . '">' . formatarNotaPDF($aluno['mac']) . '</td>';
    
    // NPT: só aparece para classes normais
    if ($bimestre != 3 || ($bimestre == 3 && !$is_classe_exame)) {
        $html .= '<td class="text-center ' . $nota_class . '">' . formatarNotaPDF($aluno['npt']) . '</td>';
    }
    
    // Exames para classes de exame
    if ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua) {
        $cor_exame_normal = getCorNota($aluno['exame_normal'], $is_ensino_fundamental);
        $cor_exame_recurso = getCorNota($aluno['exame_recurso'], $is_ensino_fundamental);
        $class_exame_normal = ($cor_exame_normal == '#28a745') ? 'nota-positiva' : 'nota-negativa';
        $class_exame_recurso = ($cor_exame_recurso == '#28a745') ? 'nota-positiva' : 'nota-negativa';
        
        $html .= '<td class="text-center ' . $class_exame_normal . '">' . formatarNotaPDF($aluno['exame_normal']) . '</td>
                  <td class="text-center ' . $class_exame_recurso . '">' . formatarNotaPDF($aluno['exame_recurso']) . '</td>';
    } elseif ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua) {
        $cor_exame_oral = getCorNota($aluno['exame_oral'], $is_ensino_fundamental);
        $cor_exame_escrito = getCorNota($aluno['exame_escrito'], $is_ensino_fundamental);
        $cor_exame_recurso = getCorNota($aluno['exame_recurso'], $is_ensino_fundamental);
        $class_exame_oral = ($cor_exame_oral == '#28a745') ? 'nota-positiva' : 'nota-negativa';
        $class_exame_escrito = ($cor_exame_escrito == '#28a745') ? 'nota-positiva' : 'nota-negativa';
        $class_exame_recurso = ($cor_exame_recurso == '#28a745') ? 'nota-positiva' : 'nota-negativa';
        
        $html .= '<td class="text-center ' . $class_exame_oral . '">' . formatarNotaPDF($aluno['exame_oral']) . '</td>
                  <td class="text-center ' . $class_exame_escrito . '">' . formatarNotaPDF($aluno['exame_escrito']) . '</td>
                  <td class="text-center ' . $class_exame_recurso . '">' . formatarNotaPDF($aluno['exame_recurso']) . '</td>';
    } elseif ($bimestre == 3) {
        $cor_exame = getCorNota($aluno['exame_normal'], $is_ensino_fundamental);
        $class_exame = ($cor_exame == '#28a745') ? 'nota-positiva' : 'nota-negativa';
        $html .= '<td class="text-center ' . $class_exame . '">' . formatarNotaPDF($aluno['exame_normal']) . '</td>';
    }
    
    $html .= '<td class="text-center ' . $nota_class . '"><strong>' . number_format($media, 1) . '</strong></td>
            <td class="text-center"><span class="' . $badge_class . '">' . $situacao_texto . '</span></td>
        </tr>';
}

$html .= '
    </tbody>
</table>

<div class="assinatura">
    <div class="assinatura-linha"></div>
    <div class="assinatura-texto">' . htmlspecialchars($professor['professor_nome']) . '</div>
    <div class="assinatura-texto">Professor(a) Responsável</div>
</div>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Emitido em ' . date('d/m/Y H:i:s') . '
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
$options->set('isFontSubsettingEnabled', true);

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $options->set('chroot', 'C:/xampp/htdocs');
}

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$nome_arquivo = 'mini_pauta_' . $turma['ano'] . 'ª_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . $bimestre . 'B_' . date('Ymd_His') . '.pdf';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>