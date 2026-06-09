<?php
// escola/professor/relatorios/gerar_pdf_pautas_gerais.php - Gerar PDF das Pautas Gerais

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
function getCorNota($nota, $is_ensino_fundamental) {
    if ($nota === null || $nota === '' || $nota <= 0) {
        return '';
    }
    
    if ($is_ensino_fundamental) {
        // Ensino Fundamental (1ª à 6ª Classe) - Escala 0-10
        if ($nota >= 4.5) {
            return 'cor-verde';
        } else {
            return 'cor-vermelha';
        }
    } else {
        // Ensino Secundário (7ª à 12ª Classe) - Escala 0-20
        if ($nota >= 9.5) {
            return 'cor-verde';
        } else {
            return 'cor-vermelha';
        }
    }
}

// ============================================
// FUNÇÃO PARA CALCULAR MÉDIA DO 3º BIMESTRE
// ============================================
function calcularMediaFinal3Bimestre($mac, $npt, $exame_normal, $exame_recurso, $exame_oral, $exame_escrito, $is_classe_exame, $is_disciplina_lingua) {
    if ($is_classe_exame) {
        // Classes de Exame (6ª, 9ª, 12ª) - NPT NÃO é considerado
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
        // Classes normais
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
            $media_calculada = calcularMediaFinal3Bimestre(
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
    
    // Calcular situação final
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

function getNotaFormatada($nota) {
    if (is_null($nota) || $nota == 0) return '-';
    // MAC e NPT exibem o valor original sem arredondamento
    return number_format($nota, 1);
}

function getNotaFormatadaMedia($nota) {
    if (is_null($nota) || $nota == 0) return '-';
    // Médias exibem valor arredondado
    return number_format(round($nota, 1), 1);
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
            margin: 1.2cm 0.8cm;
            size: A4 landscape;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 7pt;
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
            font-size: 11pt;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        
        .escola-info {
            font-size: 6pt;
            color: #666;
        }
        
        .titulo {
            text-align: center;
            font-size: 10pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 5px 0 2px 0;
            color: #006B3E;
        }
        
        .subtitulo {
            text-align: center;
            font-size: 7pt;
            color: #555;
            margin-bottom: 8px;
        }
        
        .info-section {
            margin-bottom: 8px;
            padding: 5px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 6.5pt;
        }
        
        .info-row {
            display: inline-block;
            margin-right: 12px;
        }
        
        .info-label {
            font-weight: bold;
        }
        
        .stats-line {
            text-align: center;
            margin-bottom: 8px;
            white-space: nowrap;
        }
        
        .stats-box {
            display: inline-block;
            width: 55px;
            margin: 0 2px;
            padding: 3px 2px;
            background: #f8f9fa;
            text-align: center;
            border-radius: 3px;
            border: 0.5px solid #e0e0e0;
        }
        
        .stats-number {
            font-size: 8pt;
            font-weight: bold;
            line-height: 1.1;
        }
        
        .stats-label {
            font-size: 5pt;
            color: #666;
            margin-top: 1px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 5px 0;
            font-size: 6pt;
        }
        
        th {
            background: #006B3E;
            color: white;
            padding: 4px 2px;
            text-align: center;
            font-weight: bold;
            font-size: 6pt;
        }
        
        td {
            border: 0.5px solid #ddd;
            padding: 3px 2px;
            vertical-align: middle;
        }
        
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        .cor-verde {
            color: #28a745 !important;
            font-weight: bold;
        }
        
        .cor-vermelha {
            color: #dc3545 !important;
            font-weight: bold;
        }
        
        .badge-aprovado {
            background: #d4edda;
            color: #155724;
            padding: 2px 5px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-recuperacao {
            background: #fff3cd;
            color: #856404;
            padding: 2px 5px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-reprovado {
            background: #f8d7da;
            color: #721c24;
            padding: 2px 5px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-pendente {
            background: #e9ecef;
            color: #6c757d;
            padding: 2px 5px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 5pt;
            color: #999;
            border-top: 0.5px solid #ddd;
            padding-top: 4px;
            background: white;
        }
        
        .legenda {
            margin-top: 8px;
            padding: 5px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 5.5pt;
        }
        
        .legenda-item {
            display: inline-block;
            margin-right: 10px;
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
            font-size: 6pt;
            margin-top: 2px;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | NIF: ' . htmlspecialchars($escola['nif'] ?? '') . '</div>
</div>

<div class="titulo">PAUTA GERAL DE NOTAS</div>
<div class="subtitulo">
    ' . $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . htmlspecialchars($disciplina['nome']) . ' | ANO LETIVO ' . $ano_letivo_ano . '
</div>

<div class="info-section">
    <span class="info-row"><span class="info-label">Turma:</span> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</span>
    <span class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma['turno']) . '</span>
    <span class="info-row"><span class="info-label">Sala:</span> ' . ($turma['sala'] ?: '-') . '</span>
    <span class="info-row"><span class="info-label">Disciplina:</span> ' . htmlspecialchars($disciplina['nome']) . '</span>
    <span class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($professor['professor_nome']) . '</span>
    <span class="info-row"><span class="info-label">Data:</span> ' . date('d/m/Y') . '</span>
    <span class="info-row"><span class="info-label">Regras:</span> ' . ($is_ensino_fundamental ? '0-10' : '0-20') . 
    ($is_classe_exame ? ' | Classe Exame' : '') . 
    ($is_disciplina_lingua ? ' | Língua' : '') . '</span>
</div>

<!-- Estatísticas em linha única -->
<div class="stats-line">
    <div class="stats-box"><div class="stats-number">' . $total_alunos . '</div><div class="stats-label">Total</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #28a745;">' . $total_aprovados . '</div><div class="stats-label">Aprov</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #ffc107;">' . $total_recuperacao . '</div><div class="stats-label">Recup</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #dc3545;">' . $total_reprovados . '</div><div class="stats-label">Reprov</div></div>
    <div class="stats-box"><div class="stats-number">' . $media_geral_anual . '</div><div class="stats-label">Média</div></div>
    <div class="stats-box"><div class="stats-number">' . $percentual_aprovacao . '%</div><div class="stats-label">Aprov%</div></div>
</div>

<table>
    <thead>
        <tr>
            <th rowspan="2" width="4%">#</th>
            <th rowspan="2" width="18%">Aluno</th>
            <th rowspan="2" width="7%">Matrícula</th>
            <th colspan="3">1º Bimestre</th>
            <th colspan="3">2º Bimestre</th>';
            
if ($is_classe_exame) {
    if ($is_disciplina_lingua) {
        $html .= '<th colspan="4">3º Bimestre</th>';
    } else {
        $html .= '<th colspan="3">3º Bimestre</th>';
    }
} else {
    $html .= '<th colspan="3">3º Bimestre</th>';
}

$html .= '
            <th rowspan="2" width="7%">Média<br>Anual</th>
            <th rowspan="2" width="9%">Situação</th>
        </tr>
        <tr>
            <th>MAC</th><th>NPT</th><th>Média</th>
            <th>MAC</th><th>NPT</th><th>Média</th>';
            
if ($is_classe_exame) {
    if ($is_disciplina_lingua) {
        $html .= '<th>MAC</th><th>Exame Oral</th><th>Exame Escrito</th><th>Média</th>';
    } else {
        $html .= '<th>MAC</th><th>Exame</th><th>Média</th>';
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
    
    $cor_b1_mac = getCorNota($b1['mac'], $is_ensino_fundamental);
    $cor_b1_npt = getCorNota($b1['npt'], $is_ensino_fundamental);
    $cor_b1_media = getCorNota($b1['media_final'], $is_ensino_fundamental);
    $cor_b2_mac = getCorNota($b2['mac'], $is_ensino_fundamental);
    $cor_b2_npt = getCorNota($b2['npt'], $is_ensino_fundamental);
    $cor_b2_media = getCorNota($b2['media_final'], $is_ensino_fundamental);
    $cor_b3_mac = getCorNota($b3['mac'], $is_ensino_fundamental);
    $cor_b3_npt = getCorNota($b3['npt'], $is_ensino_fundamental);
    $cor_b3_media = getCorNota($b3['media_final'], $is_ensino_fundamental);
    $cor_media_anual = getCorNota($aluno['media_anual'], $is_ensino_fundamental);
    
    $html .= '
        <tr>
            <td class="text-center">' . $contador++ . '</td>
            <td class="text-left">' . htmlspecialchars(substr($aluno['nome'], 0, 30)) . '</td>
            <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
            <!-- 1º Bimestre - MAC e NPT sem arredondamento, Média arredondada -->
            <td class="text-center ' . $cor_b1_mac . '">' . getNotaFormatada($b1['mac']) . '</td>
            <td class="text-center ' . $cor_b1_npt . '">' . getNotaFormatada($b1['npt']) . '</td>
            <td class="text-center ' . $cor_b1_media . '"><strong>' . getNotaFormatadaMedia($b1['media_final']) . '</strong></td>
            <!-- 2º Bimestre - MAC e NPT sem arredondamento, Média arredondada -->
            <td class="text-center ' . $cor_b2_mac . '">' . getNotaFormatada($b2['mac']) . '</td>
            <td class="text-center ' . $cor_b2_npt . '">' . getNotaFormatada($b2['npt']) . '</td>
            <td class="text-center ' . $cor_b2_media . '"><strong>' . getNotaFormatadaMedia($b2['media_final']) . '</strong></td>
            <!-- 3º Bimestre -->';
    
    if ($is_classe_exame) {
        if ($is_disciplina_lingua) {
            $cor_b3_exame_oral = getCorNota($b3['exame_oral'], $is_ensino_fundamental);
            $cor_b3_exame_escrito = getCorNota($b3['exame_escrito'], $is_ensino_fundamental);
            $html .= '<td class="text-center ' . $cor_b3_mac . '">' . getNotaFormatada($b3['mac']) . '</td>
            <td class="text-center ' . $cor_b3_exame_oral . '">' . getNotaFormatada($b3['exame_oral']) . '</td>
            <td class="text-center ' . $cor_b3_exame_escrito . '">' . getNotaFormatada($b3['exame_escrito']) . '</td>
            <td class="text-center ' . $cor_b3_media . '"><strong>' . getNotaFormatadaMedia($b3['media_final']) . '</strong></td>';
        } else {
            $cor_b3_exame = getCorNota($b3['exame_normal'], $is_ensino_fundamental);
            $html .= '<td class="text-center ' . $cor_b3_mac . '">' . getNotaFormatada($b3['mac']) . '</td>
            <td class="text-center ' . $cor_b3_exame . '">' . getNotaFormatada($b3['exame_normal']) . '</td>
            <td class="text-center ' . $cor_b3_media . '"><strong>' . getNotaFormatadaMedia($b3['media_final']) . '</strong></td>';
        }
    } else {
        $html .= '<td class="text-center ' . $cor_b3_mac . '">' . getNotaFormatada($b3['mac']) . '</td>
        <td class="text-center ' . $cor_b3_npt . '">' . getNotaFormatada($b3['npt']) . '</td>
        <td class="text-center ' . $cor_b3_media . '"><strong>' . getNotaFormatadaMedia($b3['media_final']) . '</strong></td>';
    }
    
    $situacao_final = $aluno['situacao_final'];
    $badge_class = '';
    switch ($situacao_final) {
        case 'aprovado': $badge_class = 'badge-aprovado'; break;
        case 'recuperacao': $badge_class = 'badge-recuperacao'; break;
        case 'reprovado': $badge_class = 'badge-reprovado'; break;
        default: $badge_class = 'badge-pendente';
    }
    $situacao_texto = getSituacaoTexto($situacao_final);
    
    // Média Anual arredondada
    $html .= '<td class="text-center ' . $cor_media_anual . '"><strong>' . getNotaFormatadaMedia($aluno['media_anual']) . '</strong></td>
            <td class="text-center"><span class="' . $badge_class . '">' . $situacao_texto . '</span></td>
        </tr>
    ';
}

$html .= '
    </tbody>
</table>

<div class="legenda">
    <strong>Legenda:</strong>
    <span class="legenda-item"><span class="cor-verde">●</span> Nota positiva (' . ($is_ensino_fundamental ? '≥ 4.5' : '≥ 9.5') . ')</span>
    <span class="legenda-item"><span class="cor-vermelha">●</span> Nota negativa (' . ($is_ensino_fundamental ? '< 4.5' : '< 9.5') . ')</span>
    <span class="legenda-item"><span class="badge-aprovado">Aprovado</span> Média ' . ($is_ensino_fundamental ? '> 5' : '> 10') . '</span>
    <span class="legenda-item"><span class="badge-recuperacao">Recuperação</span> Média ' . ($is_ensino_fundamental ? '= 5' : '= 10') . '</span>
    <span class="legenda-item"><span class="badge-reprovado">Reprovado</span> Média ' . ($is_ensino_fundamental ? '< 5' : '< 10') . '</span>
';

if ($is_classe_exame) {
    if ($is_disciplina_lingua) {
        $html .= '<br><span class="legenda-item"><strong>Regra Classe Exame (3º Bimestre):</strong> Média = (MAC × 40%) + ((Oral + Escrito)/2 × 60%)</span>';
    } else {
        $html .= '<br><span class="legenda-item"><strong>Regra Classe Exame (3º Bimestre):</strong> Média = (MAC × 40%) + (Exame × 60%)</span>';
    }
}

$html .= '
</div>

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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$nome_arquivo = 'pauta_geral_' . $turma['ano'] . 'ª_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . date('Ymd_His') . '.pdf';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>