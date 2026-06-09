<?php
// escola/professor/minipauta_pdf.php - Gerar Mini Pauta de Notas

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
    die('Parâmetros inválidos. Turma e disciplina são obrigatórios.');
}

// ============================================
// BUSCAR DADOS DO PROFESSOR (NOME CORRETO)
// ============================================
$sql_professor = "SELECT nome, email, telefone FROM funcionarios WHERE id = :professor_id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':professor_id' => $professor_id]);
$dados_professor = $stmt_professor->fetch(PDO::FETCH_ASSOC);
$nome_professor = $dados_professor ? $dados_professor['nome'] : $professor['nome'];

// ============================================
// VERIFICAR ACESSO DO PROFESSOR
// ============================================
$sql_verifica = "
    SELECT COUNT(*) 
    FROM professor_disciplina_turma 
    WHERE professor_id = :professor_id 
    AND turma_id = :turma_id 
    AND disciplina_id = :disciplina_id
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([
    ':professor_id' => $professor_id,
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id
]);
if ($stmt_verifica->fetchColumn() == 0) {
    die('Acesso negado! Você não tem permissão para acessar esta turma/disciplina.');
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);

if (!$ano_letivo) {
    $sql_ano = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC LIMIT 1";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute();
    $ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
}

$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    $escola = ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
}

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "SELECT id, nome, ano, turno, sala, capacidade FROM turmas WHERE id = :id AND escola_id = :escola_id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada!');
}

// ============================================
// BUSCAR DADOS DA DISCIPLINA
// ============================================
$sql_disciplina = "SELECT id, nome, codigo, carga_horaria FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

if (!$disciplina) {
    die('Disciplina não encontrada!');
}

// ============================================
// DETERMINAR REGRAS DE AVALIAÇÃO
// ============================================
$classe_ano = $turma['ano'];
$is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
$is_ensino_fundamental = ($classe_ano <= 6);
$limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
$nota_minima_verde = $is_ensino_fundamental ? 4.5 : 9.5;

// Verificar se é disciplina de língua
$disciplina_nome = $disciplina['nome'];
$is_disciplina_lingua = (stripos($disciplina_nome, 'português') !== false || 
                          stripos($disciplina_nome, 'inglês') !== false ||
                          stripos($disciplina_nome, 'portugues') !== false ||
                          stripos($disciplina_nome, 'ingles') !== false);

// ============================================
// BUSCAR ALUNOS (COM NOTAS)
// ============================================
// Primeiro, buscar todos os alunos da turma
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.genero,
        e.data_nascimento
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':turma_id' => $turma_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Depois, buscar as notas para estes alunos
$notas_por_aluno = [];
if (!empty($alunos)) {
    $sql_notas = "
        SELECT 
            estudante_id,
            mac,
            npt,
            exame_normal,
            exame_recurso,
            exame_especial,
            exame_oral,
            exame_escrito,
            media_final,
            status,
            observacao_academica
        FROM notas
        WHERE disciplina_id = :disciplina_id 
        AND turma_id = :turma_id
        AND bimestre = :bimestre 
        AND ano_letivo_id = :ano_letivo_id
    ";
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute([
        ':disciplina_id' => $disciplina_id,
        ':turma_id' => $turma_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    
    while ($row = $stmt_notas->fetch(PDO::FETCH_ASSOC)) {
        $notas_por_aluno[$row['estudante_id']] = $row;
    }
}

// ============================================
// CALCULAR MÉDIAS E SITUAÇÕES
// ============================================
function calcularMediaFinalMiniPauta($nota, $is_classe_exame, $is_ensino_fundamental, $bimestre, $is_disciplina_lingua) {
    if (!$nota) return 0;
    
    $mac = floatval($nota['mac'] ?? 0);
    $npt = floatval($nota['npt'] ?? 0);
    $exame_normal = floatval($nota['exame_normal'] ?? 0);
    $exame_recurso = floatval($nota['exame_recurso'] ?? 0);
    $exame_oral = floatval($nota['exame_oral'] ?? 0);
    $exame_escrito = floatval($nota['exame_escrito'] ?? 0);
    
    // BIMESTRE 1 e 2: Apenas MAC
    if ($bimestre == 1 || $bimestre == 2) {
        return round($mac, 1);
    }
    
    // BIMESTRE 3: Avaliação Final
    if ($bimestre == 3) {
        // Para CLASSES DE EXAME (6ª, 9ª, 12ª)
        if ($is_classe_exame) {
            $media_parcial = $mac;
            
            // Para DISCIPLINAS DE LÍNGUA
            if ($is_disciplina_lingua) {
                if ($exame_recurso > 0) {
                    return round($exame_recurso, 1);
                } elseif ($exame_oral > 0 && $exame_escrito > 0) {
                    $media_exame = ($exame_oral + $exame_escrito) / 2;
                    return round(($media_parcial * 0.4) + ($media_exame * 0.6), 1);
                } elseif ($exame_oral > 0) {
                    return round(($media_parcial * 0.4) + ($exame_oral * 0.6), 1);
                } elseif ($exame_escrito > 0) {
                    return round(($media_parcial * 0.4) + ($exame_escrito * 0.6), 1);
                } else {
                    return round($media_parcial, 1);
                }
            } 
            // Para as restantes disciplinas
            else {
                if ($exame_recurso > 0) {
                    return round($exame_recurso, 1);
                } elseif ($exame_normal > 0) {
                    return round(($media_parcial * 0.4) + ($exame_normal * 0.6), 1);
                } else {
                    return round($media_parcial, 1);
                }
            }
        } 
        // Para CLASSES NORMAIS (não exame)
        else {
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
    
    return 0;
}

function determinarSituacaoMiniPauta($media, $limite_aprovacao, $bimestre) {
    if ($media <= 0) return 'pendente';
    
    if ($bimestre == 3) {
        if ($media > $limite_aprovacao) return 'aprovado';
        if ($media == $limite_aprovacao) return 'recuperacao';
        if ($media < $limite_aprovacao) return 'reprovado';
    } else {
        if ($media > $limite_aprovacao) return 'bom';
        if ($media == $limite_aprovacao) return 'suficiente';
        if ($media >= $limite_aprovacao - 1) return 'insuficiente';
        return 'fraco';
    }
    
    return 'pendente';
}

// Processar cada aluno com suas notas
$alunos_processados = [];
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_notas = 0;
$count_com_nota = 0;

foreach ($alunos as $aluno) {
    $nota = isset($notas_por_aluno[$aluno['id']]) ? $notas_por_aluno[$aluno['id']] : null;
    
    // Calcular média
    $media_calculada = calcularMediaFinalMiniPauta($nota, $is_classe_exame, $is_ensino_fundamental, $bimestre, $is_disciplina_lingua);
    
    // Usar média existente ou a calculada
    $media_final = ($nota && $nota['media_final'] > 0) ? $nota['media_final'] : $media_calculada;
    
    // Determinar situação
    if ($nota && $nota['status']) {
        $situacao = $nota['status'];
    } else {
        $situacao = determinarSituacaoMiniPauta($media_final, $limite_aprovacao, $bimestre);
    }
    
    // Atualizar estatísticas
    if ($media_final > 0) {
        $soma_notas += $media_final;
        $count_com_nota++;
        
        if ($bimestre == 3) {
            if ($situacao == 'aprovado') $total_aprovados++;
            elseif ($situacao == 'recuperacao') $total_recuperacao++;
            elseif ($situacao == 'reprovado') $total_reprovados++;
        }
    }
    
    $alunos_processados[] = [
        'id' => $aluno['id'],
        'nome' => $aluno['nome'],
        'matricula' => $aluno['matricula'],
        'genero' => $aluno['genero'],
        'data_nascimento' => $aluno['data_nascimento'],
        'mac' => $nota['mac'] ?? null,
        'npt' => $nota['npt'] ?? null,
        'exame_normal' => $nota['exame_normal'] ?? null,
        'exame_recurso' => $nota['exame_recurso'] ?? null,
        'exame_especial' => $nota['exame_especial'] ?? null,
        'exame_oral' => $nota['exame_oral'] ?? null,
        'exame_escrito' => $nota['exame_escrito'] ?? null,
        'media_final' => $media_final,
        'situacao' => $situacao,
        'observacao' => $nota['observacao_academica'] ?? ''
    ];
}

$total_alunos = count($alunos_processados);
$media_geral = $count_com_nota > 0 ? round($soma_notas / $count_com_nota, 1) : 0;
$percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getCorNotaMiniPauta($nota, $is_ensino_fundamental) {
    if ($nota <= 0) return '#6c757d';
    $limite = $is_ensino_fundamental ? 4.5 : 9.5;
    return ($nota >= $limite) ? '#28a745' : '#dc3545';
}

function formatarNotaMiniPauta($valor) {
    if ($valor === null || $valor === '' || $valor == 0) return '-';
    return number_format(floatval($valor), 1);
}

function getSituacaoBadge($situacao, $bimestre) {
    if ($bimestre == 3) {
        switch ($situacao) {
            case 'aprovado': return 'Aprovado';
            case 'recuperacao': return 'Recuperação';
            case 'reprovado': return 'Reprovado';
            default: return 'Pendente';
        }
    } else {
        switch ($situacao) {
            case 'bom': return 'Bom';
            case 'suficiente': return 'Suficiente';
            case 'insuficiente': return 'Insuficiente';
            case 'fraco': return 'Fraco';
            default: return 'Pendente';
        }
    }
}

// ============================================
// GERAR HTML PARA PDF
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
        
        .stats-line {
            text-align: center;
            margin-bottom: 8px;
            white-space: nowrap;
        }
        
        .stats-box {
            display: inline-block;
            width: 65px;
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
        
        .nota-positiva { color: #28a745; font-weight: bold; }
        .nota-negativa { color: #dc3545; font-weight: bold; }
        .nota-neutral { color: #6c757d; }
        
        .badge-aprovado {
            background: #d4edda;
            color: #155724;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-recuperacao {
            background: #fff3cd;
            color: #856404;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-reprovado {
            background: #f8d7da;
            color: #721c24;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-pendente {
            background: #e9ecef;
            color: #6c757d;
            padding: 1px 4px;
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
            font-size: 5.5pt;
            color: #999;
            border-top: 0.5px solid #ddd;
            padding-top: 4px;
            background: white;
        }
        
        .assinatura {
            margin-top: 15px;
            text-align: center;
        }
        
        .assinatura-linha {
            display: inline-block;
            width: 180px;
            border-top: 0.5px solid #000;
            margin-top: 15px;
        }
        
        .assinatura-texto {
            font-size: 7pt;
            margin-top: 2px;
        }
        
        .assinaturas {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
        }
        
        .assinatura-item {
            text-align: center;
            width: 45%;
        }
        
        .legenda-cores {
            font-size: 5.5pt;
            text-align: center;
            margin-top: 5px;
            padding: 3px;
            background: #f8f9fa;
            border-radius: 3px;
        }
        
        .cor-verde { color: #28a745; font-weight: bold; }
        .cor-vermelha { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | NIF: ' . htmlspecialchars($escola['nif'] ?? '') . '</div>
</div>

<div class="titulo">MINI PAUTA DE NOTAS</div>
<div class="subtitulo">
    ' . $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . htmlspecialchars($disciplina['nome']) . ' | ' . $bimestre . 'º BIMESTRE | ANO LETIVO ' . $ano_letivo_ano . '
</div>

<div class="info-section">
    <span class="info-row"><span class="info-label">Turma:</span> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</span>
    <span class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma['turno']) . '</span>
    <span class="info-row"><span class="info-label">Sala:</span> ' . ($turma['sala'] ?: '-') . '</span>
    <span class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($nome_professor) . '</span>
    <span class="info-row"><span class="info-label">Data:</span> ' . date('d/m/Y') . '</span>
</div>

<div class="stats-line">
    <div class="stats-box"><div class="stats-number">' . $total_alunos . '</div><div class="stats-label">Total</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #28a745;">' . $total_aprovados . '</div><div class="stats-label">Aprovados</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #ffc107;">' . $total_recuperacao . '</div><div class="stats-label">Recuperação</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #dc3545;">' . $total_reprovados . '</div><div class="stats-label">Reprovados</div></div>
    <div class="stats-box"><div class="stats-number">' . $media_geral . '</div><div class="stats-label">Média</div></div>
    <div class="stats-box"><div class="stats-number">' . $percentual_aprovacao . '%</div><div class="stats-label">Aproveit.</div></div>
</div>

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
            <th width="4%">Nº</th>
            <th width="30%">Aluno</th>
            <th width="10%">Matrícula</th>
            <th width="10%">MAC</th>
            ' . ($bimestre != 3 || ($bimestre == 3 && !$is_classe_exame) ? '<th width="10%">NPT</th>' : '') . '
            ' . ($bimestre == 3 ? '<th width="12%">Exame</th>' : '') . '
            <th width="12%">Média</th>
            <th width="12%">Situação</th>
        </tr>
    </thead>
    <tbody>';

foreach ($alunos_processados as $index => $aluno) {
    $media = $aluno['media_final'];
    $cor_media = getCorNotaMiniPauta($media, $is_ensino_fundamental);
    $nota_class = ($cor_media == '#28a745') ? 'nota-positiva' : (($cor_media == '#dc3545') ? 'nota-negativa' : 'nota-neutral');
    $situacao_texto = getSituacaoBadge($aluno['situacao'], $bimestre);
    
    $badge_class = '';
    if ($bimestre == 3) {
        switch ($aluno['situacao']) {
            case 'aprovado': $badge_class = 'badge-aprovado'; break;
            case 'recuperacao': $badge_class = 'badge-recuperacao'; break;
            case 'reprovado': $badge_class = 'badge-reprovado'; break;
            default: $badge_class = 'badge-pendente';
        }
    } else {
        switch ($aluno['situacao']) {
            case 'bom': $badge_class = 'badge-aprovado'; break;
            case 'suficiente': $badge_class = 'badge-recuperacao'; break;
            case 'insuficiente': $badge_class = 'badge-reprovado'; break;
            default: $badge_class = 'badge-pendente';
        }
    }
    
    $cor_mac = getCorNotaMiniPauta($aluno['mac'], $is_ensino_fundamental);
    $class_mac = ($cor_mac == '#28a745') ? 'nota-positiva' : (($cor_mac == '#dc3545') ? 'nota-negativa' : 'nota-neutral');
    
    $html .= '
        <tr>
            <td class="text-center">' . ($index + 1) . '</td>
            <td class="text-left"><strong>' . strtoupper(htmlspecialchars($aluno['nome'])) . '</strong></td>
            <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
            <td class="text-center ' . $class_mac . '">' . formatarNotaMiniPauta($aluno['mac']) . '</td>';
    
    if ($bimestre != 3 || ($bimestre == 3 && !$is_classe_exame)) {
        $cor_npt = getCorNotaMiniPauta($aluno['npt'], $is_ensino_fundamental);
        $class_npt = ($cor_npt == '#28a745') ? 'nota-positiva' : (($cor_npt == '#dc3545') ? 'nota-negativa' : 'nota-neutral');
        $html .= '<td class="text-center ' . $class_npt . '">' . formatarNotaMiniPauta($aluno['npt']) . '</td>';
    }
    
    if ($bimestre == 3) {
        // Para disciplinas de língua em classe de exame, mostrar a média dos exames
        if ($is_classe_exame && $is_disciplina_lingua) {
            $exame_oral = floatval($aluno['exame_oral'] ?? 0);
            $exame_escrito = floatval($aluno['exame_escrito'] ?? 0);
            $exame_normal = floatval($aluno['exame_normal'] ?? 0);
            $exame_recurso = floatval($aluno['exame_recurso'] ?? 0);
            
            if ($exame_recurso > 0) {
                $exame = $exame_recurso;
            } elseif ($exame_oral > 0 && $exame_escrito > 0) {
                $exame = ($exame_oral + $exame_escrito) / 2;
            } elseif ($exame_oral > 0) {
                $exame = $exame_oral;
            } elseif ($exame_escrito > 0) {
                $exame = $exame_escrito;
            } else {
                $exame = $exame_normal;
            }
        } else {
            $exame = $aluno['exame_recurso'] ?? 0;
            if ($exame <= 0) $exame = $aluno['exame_normal'] ?? 0;
        }
        
        $cor_exame = getCorNotaMiniPauta($exame, $is_ensino_fundamental);
        $class_exame = ($cor_exame == '#28a745') ? 'nota-positiva' : (($cor_exame == '#dc3545') ? 'nota-negativa' : 'nota-neutral');
        $html .= '<td class="text-center ' . $class_exame . '">' . formatarNotaMiniPauta($exame) . '</td>';
    }
    
    $html .= '
            <td class="text-center ' . $nota_class . '"><strong>' . number_format($media, 1) . '</strong></td>
            <td class="text-center"><span class="' . $badge_class . '">' . $situacao_texto . '</span></td>
        </tr>';
}

$html .= '
    </tbody>
</table>

<div class="assinaturas">
    <div class="assinatura-item">
        <div class="assinatura-linha"></div>
        <div class="assinatura-texto">' . htmlspecialchars($nome_professor) . '</div>
        <div class="assinatura-texto">Professor(a) Responsável</div>
    </div>
    <div class="assinatura-item">
        <div class="assinatura-linha"></div>
        <div class="assinatura-texto">' . htmlspecialchars($escola['nome'] ?? 'Direção') . '</div>
        <div class="assinatura-texto">Direção / Carimbo da Escola</div>
    </div>
</div>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Mini Pauta emitida em ' . date('d/m/Y H:i:s') . ' | Página {PAGE_NUM} de {PAGE_COUNT}
</div>

</body>
</html>
';

// ============================================
// CONFIGURAR E GERAR PDF
// ============================================
try {
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isFontSubsettingEnabled', true);
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $options->set('chroot', realpath(__DIR__ . '/../../'));
    }
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $canvas = $dompdf->getCanvas();
    $canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {
        $text = "Página $pageNumber de $pageCount";
        $font = $fontMetrics->getFont("DejaVu Sans", "normal");
        $width = $fontMetrics->getTextWidth($text, $font, 8);
        $x = ($canvas->get_width() - $width) / 2;
        $y = $canvas->get_height() - 15;
        $canvas->text($x, $y, $text, $font, 8, array(0.6, 0.6, 0.6));
    });
    
    $nome_arquivo = 'mini_pauta_' . $turma['ano'] . 'ª_' . preg_replace('/[^a-zA-Z0-9]/', '_', $turma['nome']) . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $disciplina['nome']) . '_' . $bimestre . 'B_' . date('Ymd_His') . '.pdf';
    $nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);
    
    if (ob_get_level()) ob_end_clean();
    
    $dompdf->stream($nome_arquivo, ['Attachment' => true]);
    exit;
    
} catch (Exception $e) {
    die('Erro ao gerar PDF: ' . $e->getMessage());
}
?>