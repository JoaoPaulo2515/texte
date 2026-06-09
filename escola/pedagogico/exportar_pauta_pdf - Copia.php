<?php
// escola/pedagogico/exportar_pauta_pdf.php - Exportar Pauta Final para PDF com Critérios Completos

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

// Buscar parâmetros de avaliação
$sql_parametros = "
    SELECT * FROM parametros_avaliacao 
    WHERE escola_id = :escola_id 
    ORDER BY classe_inicio ASC
";
$stmt_parametros = $conn->prepare($sql_parametros);
$stmt_parametros->execute([':escola_id' => $escola_id]);
$parametros = $stmt_parametros->fetchAll(PDO::FETCH_ASSOC);

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
$nome_escola = $escola_nome_completo ? $escola_nome_completo['nome'] : 'SIGE Angola';

// Buscar parâmetro específico para a classe
$parametro_atual = null;
foreach ($parametros as $p) {
    if ($info['classe_ordem'] >= $p['classe_inicio'] && $info['classe_ordem'] <= $p['classe_fim']) {
        $parametro_atual = $p;
        break;
    }
}

if (!$parametro_atual) {
    $parametro_atual = [
        'escala_maxima' => 20,
        'nota_minima_aprovacao' => 10,
        'nota_minima_recuperacao' => 7,
        'bimestres_por_ano' => 3,
        'permite_exame_recurso' => 1,
        'permite_exame_especial' => 1,
        'permite_exame_oral' => 1,
        'permite_exame_escrito' => 1
    ];
}

// Buscar disciplinas da turma
$sql_disciplinas = "
    SELECT DISTINCT d.id, d.nome, d.codigo, 
           CASE WHEN d.nome LIKE '%Português%' OR d.nome LIKE '%Inglês%' OR d.nome LIKE '%Espanhol%' OR d.nome LIKE '%Francês%' THEN 1 ELSE 0 END as is_lingua
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

// Estrutura para armazenar notas por bimestre
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
    
    // Organizar notas por disciplina e bimestre
    $notas_por_disciplina = [];
    foreach ($notas as $nota) {
        $disc_id = $nota['disciplina_id'];
        $bimestre = $nota['bimestre'];
        
        if (!isset($notas_por_disciplina[$disc_id])) {
            $notas_por_disciplina[$disc_id] = [
                'bimestres' => [],
                'media_anual' => 0,
                'resultado_final' => '',
                'em_exame' => false
            ];
        }
        
        // Calcular nota do bimestre (MAC + NPT)
        $nota_bimestre = 0;
        $componentes = 0;
        
        if ($nota['mac'] !== null) {
            $nota_bimestre += (float)$nota['mac'];
            $componentes++;
        }
        if ($nota['npt'] !== null) {
            $nota_bimestre += (float)$nota['npt'];
            $componentes++;
        }
        
        $media_bimestre = $componentes > 0 ? round($nota_bimestre / $componentes, 1) : 0;
        
        // Verificar se precisa de exame
        $precisa_exame = $media_bimestre < $parametro_atual['nota_minima_aprovacao'];
        
        // Calcular nota com exame se disponível
        $nota_final_bimestre = $media_bimestre;
        if ($precisa_exame && $parametro_atual['permite_exame_recurso']) {
            if ($nota['exame_recurso'] !== null) {
                $nota_final_bimestre = round(($media_bimestre + (float)$nota['exame_recurso']) / 2, 1);
            } elseif ($nota['exame_especial'] !== null) {
                $nota_final_bimestre = round(($media_bimestre + (float)$nota['exame_especial']) / 2, 1);
            }
        }
        
        // Para disciplinas de língua, considerar exame oral e escrito
        $is_lingua = false;
        foreach ($disciplinas as $disc) {
            if ($disc['id'] == $disc_id && $disc['is_lingua']) {
                $is_lingua = true;
                break;
            }
        }
        
        if ($is_lingua && $parametro_atual['permite_exame_oral'] && $parametro_atual['permite_exame_escrito']) {
            $nota_oral = $nota['exame_oral'] !== null ? (float)$nota['exame_oral'] : 0;
            $nota_escrito = $nota['exame_escrito'] !== null ? (float)$nota['exame_escrito'] : 0;
            if ($nota_oral > 0 || $nota_escrito > 0) {
                $media_exames = ($nota_oral + $nota_escrito) / 2;
                $nota_final_bimestre = round(($nota_final_bimestre + $media_exames) / 2, 1);
            }
        }
        
        $notas_por_disciplina[$disc_id]['bimestres'][$bimestre] = [
            'mac' => $nota['mac'],
            'npt' => $nota['npt'],
            'media' => $media_bimestre,
            'nota_final' => $nota_final_bimestre,
            'precisa_exame' => $precisa_exame,
            'exame_usado' => $nota['exame_recurso'] !== null ? 'Recurso' : ($nota['exame_especial'] !== null ? 'Especial' : null)
        ];
    }
    
    // Calcular média anual e resultado final para cada disciplina
    foreach ($notas_por_disciplina as $disc_id => &$disciplina_data) {
        $soma_bimestres = 0;
        $num_bimestres = count($disciplina_data['bimestres']);
        
        foreach ($disciplina_data['bimestres'] as $bimestre_data) {
            $soma_bimestres += $bimestre_data['nota_final'];
        }
        
        $media_anual = $num_bimestres > 0 ? round($soma_bimestres / $num_bimestres, 1) : 0;
        $disciplina_data['media_anual'] = $media_anual;
        
        // Determinar resultado final
        if ($media_anual >= $parametro_atual['nota_minima_aprovacao']) {
            $disciplina_data['resultado_final'] = 'Aprovado';
            $disciplina_data['em_exame'] = false;
        } elseif ($media_anual >= $parametro_atual['nota_minima_recuperacao']) {
            $disciplina_data['resultado_final'] = 'Recuperação';
            $disciplina_data['em_exame'] = true;
        } else {
            $disciplina_data['resultado_final'] = 'Reprovado';
            $disciplina_data['em_exame'] = true;
        }
    }
    
    $alunos[$key]['notas_por_disciplina'] = $notas_por_disciplina;
    
    // Calcular média geral do aluno
    $soma_geral = 0;
    $count_disciplinas = 0;
    foreach ($notas_por_disciplina as $disciplina_data) {
        $soma_geral += $disciplina_data['media_anual'];
        $count_disciplinas++;
    }
    $media_geral = $count_disciplinas > 0 ? round($soma_geral / $count_disciplinas, 1) : 0;
    $alunos[$key]['media_geral'] = $media_geral;
    
    // Resultado final do aluno
    $reprovado_direto = false;
    $em_recuperacao = false;
    foreach ($notas_por_disciplina as $disciplina_data) {
        if ($disciplina_data['resultado_final'] == 'Reprovado') {
            $reprovado_direto = true;
        } elseif ($disciplina_data['resultado_final'] == 'Recuperação') {
            $em_recuperacao = true;
        }
    }
    
    if ($reprovado_direto) {
        $alunos[$key]['resultado_geral'] = 'Reprovado';
    } elseif ($em_recuperacao) {
        $alunos[$key]['resultado_geral'] = 'Recuperação';
    } else {
        $alunos[$key]['resultado_geral'] = 'Aprovado';
    }
}

// Calcular estatísticas da turma
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
foreach ($alunos as $a) {
    if ($a['resultado_geral'] == 'Aprovado') $total_aprovados++;
    elseif ($a['resultado_geral'] == 'Recuperação') $total_recuperacao++;
    else $total_reprovados++;
}

$bimestres = range(1, $parametro_atual['bimestres_por_ano']);

// Iniciar buffer de saída
ob_start();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pauta Final - <?php echo $info['classe_nome']; ?></title>
    <style>
        @page {
            size: A3 landscape;
            margin: 15mm;
        }
        
        body {
            font-family: "DeJaVu Sans", "Arial", sans-serif;
            font-size: 8pt;
            margin: 0;
            padding: 0;
        }
        
        .cabecario {
            text-align: center;
            font-weight: bold;
            font-size: 10pt;
            margin: 2px 0;
        }
        
        .cabecariodistrito {
            text-align: center;
            font-weight: bold;
            font-size: 9pt;
            margin: 5px 0;
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .cabecariotitulominipauta {
            text-align: center;
            font-weight: bold;
            font-size: 11pt;
            margin: 10px 0 5px 0;
        }
        
        .cabecarioturma {
            text-align: center;
            font-weight: bold;
            font-size: 9pt;
            margin: 5px 0;
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .numeropauta {
            text-decoration: underline;
        }
        
        .classe, .sala, .turma, .periodo, .numero {
            text-decoration: underline;
            margin-left: 3px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7pt;
            margin-top: 10px;
        }
        
        th {
            background: #1e5799;
            color: white;
            padding: 4px;
            text-align: center;
            border: 0.5px solid #ddd;
            font-weight: bold;
            font-size: 7pt;
        }
        
        td {
            padding: 3px;
            border: 0.5px solid #ddd;
            text-align: center;
            vertical-align: middle;
        }
        
        .text-left {
            text-align: left;
        }
        
        .nota-baixa { 
            color: #dc3545; 
            font-weight: bold; 
            background: #fff;
        }
        .nota-media { 
            color: #ffc107; 
            font-weight: bold; 
            background: #fff;
        }
        .nota-alta { 
            color: #28a745; 
            font-weight: bold; 
            background: #fff;
        }
        
        .footer {
            margin-top: 15px;
            text-align: center;
            font-size: 7pt;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 8px;
        }
        
        .signature {
            margin-top: 20px;
            width: 100%;
        }
        .signature td {
            border: none;
            text-align: center;
            padding-top: 20px;
        }
        
        .legenda {
            margin-top: 10px;
            padding: 5px;
            background: #f8f9fa;
            font-size: 6pt;
        }
        
        .stats-box {
            margin-bottom: 10px;
            padding: 5px;
            background: #e8f4f8;
            border-radius: 5px;
        }
    </style>
</head>
<body>

<!-- Cabeçalho conforme solicitado -->
<div class='cabecario'><strong>REPÚBLICA DE ANGOLA</strong></div>
<div class='cabecario'><strong>MINISTÉRIO DA EDUCAÇÃO</strong></div>
<div class='cabecario'><strong>GOVERNO DA PROVÍNCIA DO ICOLO E BENGO</strong></div>
<div class='cabecario'><strong>REPARTIÇÃO MUNICIPAL DA EDUCAÇÃO DO SEQUELE</strong></div>
<div class='cabecario'><strong><?php echo htmlspecialchars($nome_escola); ?></strong></div>

<div class='cabecariodistrito'>
    <strong>DISTRITO DO SEQUELE</strong>
    <strong>MUNICÍPIO DO SEQUELE</strong>
    <strong>PROVÍNCIA DE ICOLO E BENGO</strong>
</div>

<div class='cabecariotitulominipauta'>
    <strong>PAUTA DA CLASSE DE TRANSIÇÃO Nº <strong class='numeropauta'>__________</strong>/</strong>
    <strong class='numero'><?php echo $ano_selecionado; ?></strong>
</div>

<div class='cabecarioturma'>
    <strong>ANO LECTIVO: <strong class='classe'><?php echo $ano_selecionado; ?></strong></strong>
    <strong>PERÍODO: <strong class='sala'><?php echo $periodo; ?></strong></strong>
    <strong>CLASSE: <strong class='turma'><?php echo htmlspecialchars($info['classe_nome']); ?></strong></strong>
    <strong>TURMA: <strong class='periodo'><?php echo htmlspecialchars($info['turma_nome']); ?></strong></strong>
    <strong>SALA: <strong class='periodo'><?php echo $sala ?: '---'; ?></strong></strong>
</div>
<br>

<div class="stats-box">
    <strong>Parâmetros de Avaliação:</strong>
    Escala: 0-<?php echo $parametro_atual['escala_maxima']; ?> | 
    Aprovação: ≥ <?php echo $parametro_atual['nota_minima_aprovacao']; ?> | 
    Recuperação: ≥ <?php echo $parametro_atual['nota_minima_recuperacao']; ?> | 
    Bimestres: <?php echo $parametro_atual['bimestres_por_ano']; ?>
    <?php if ($parametro_atual['permite_exame_recurso']): ?> | ✓ Exame Recurso<?php endif; ?>
    <?php if ($parametro_atual['permite_exame_especial']): ?> | ✓ Exame Especial<?php endif; ?>
    <?php if ($parametro_atual['permite_exame_oral']): ?> | ✓ Exame Oral<?php endif; ?>
    <?php if ($parametro_atual['permite_exame_escrito']): ?> | ✓ Exame Escrito<?php endif; ?>
</div>

<div class="info-turma" style="margin-bottom: 5px; padding: 3px; background: #f5f5f5; font-size: 7pt;">
    <strong>Total de Alunos:</strong> <?php echo count($alunos); ?> | 
    <strong>Total de Disciplinas:</strong> <?php echo count($disciplinas); ?> | 
    <strong>Aprovados:</strong> <span style="color:#28a745"><?php echo $total_aprovados; ?></span> | 
    <strong>Recuperação:</strong> <span style="color:#ffc107"><?php echo $total_recuperacao; ?></span> | 
    <strong>Reprovados:</strong> <span style="color:#dc3545"><?php echo $total_reprovados; ?></span>
</div>

<table>
    <thead>
        <tr>
            <th rowspan="2">Nº</th>
            <th rowspan="2">Matrícula</th>
            <th rowspan="2">Nome do Aluno</th>
            <?php foreach ($disciplinas as $disc): ?>
                <th colspan="<?php echo $parametro_atual['bimestres_por_ano'] + 2; ?>"><?php echo htmlspecialchars($disc['codigo'] ?: substr($disc['nome'], 0, 3)); ?>
                    <?php if ($disc['is_lingua']): ?> <span style="font-size:5pt">(Língua)</span><?php endif; ?>
                </th>
            <?php endforeach; ?>
            <th rowspan="2">Média Geral</th>
            <th rowspan="2">Resultado</th>
        </tr>
        <tr>
            <?php foreach ($disciplinas as $disc): ?>
                <?php foreach ($bimestres as $b): ?>
                    <th><?php echo $b; ?>º Bim</th>
                <?php endforeach; ?>
                <th>Média</th>
                <th>Res.</th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php $i = 1; foreach ($alunos as $aluno): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($aluno['matricula'] ?: '-'); ?></td>
                <td class="text-left"><?php echo htmlspecialchars($aluno['nome']); ?></td>
                
                <?php foreach ($disciplinas as $disc): ?>
                    <?php 
                    $disc_notas = isset($aluno['notas_por_disciplina'][$disc['id']]) ? $aluno['notas_por_disciplina'][$disc['id']] : null;
                    ?>
                    <?php foreach ($bimestres as $b): ?>
                        <td>
                            <?php 
                            if ($disc_notas && isset($disc_notas['bimestres'][$b])) {
                                $nota = $disc_notas['bimestres'][$b]['nota_final'];
                                $classe_nota = '';
                                if ($nota >= 14) $classe_nota = 'nota-alta';
                                elseif ($nota >= 10) $classe_nota = 'nota-media';
                                else $classe_nota = 'nota-baixa';
                                echo '<span class="' . $classe_nota . '">' . number_format($nota, 1) . '</span>';
                                if ($disc_notas['bimestres'][$b]['precisa_exame'] && $disc_notas['bimestres'][$b]['exame_usado']) {
                                    echo '<sup>*</sup>';
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    <?php endforeach; ?>
                    <td>
                        <?php 
                        if ($disc_notas) {
                            $media = $disc_notas['media_anual'];
                            $classe_media = '';
                            if ($media >= 14) $classe_media = 'nota-alta';
                            elseif ($media >= 10) $classe_media = 'nota-media';
                            else $classe_media = 'nota-baixa';
                            echo '<strong class="' . $classe_media . '">' . number_format($media, 1) . '</strong>';
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if ($disc_notas) {
                            $resultado = $disc_notas['resultado_final'];
                            if ($resultado == 'Aprovado') echo '<span style="color:#28a745">✓</span>';
                            elseif ($resultado == 'Recuperação') echo '<span style="color:#ffc107">!</span>';
                            else echo '<span style="color:#dc3545">✗</span>';
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                <?php endforeach; ?>
                
                <td><strong><?php echo number_format($aluno['media_geral'], 1); ?></strong></td>
                <td>
                    <?php 
                    if ($aluno['resultado_geral'] == 'Aprovado') {
                        echo '<span class="nota-alta">Aprovado</span>';
                    } elseif ($aluno['resultado_geral'] == 'Recuperação') {
                        echo '<span class="nota-media">Recuperação</span>';
                    } else {
                        echo '<span class="nota-baixa">Reprovado</span>';
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="legenda">
    <strong>Legenda:</strong>
    <span class="nota-alta">Verde: ≥14</span> | 
    <span class="nota-media">Amarelo: 10-13.9</span> | 
    <span class="nota-baixa">Vermelho: &lt;10</span> | 
    * Aprovado após exame | 
    ✓ Aprovado | ! Recuperação | ✗ Reprovado
</div>

<div class="legenda">
    <strong>Critérios de Avaliação:</strong>
    • Média do Bimestre = (MAC + NPT) / 2<br>
    • Exame de Recurso: (Média + Exame) / 2 (para alunos com média ≥ 7)<br>
    • Exame Especial: (Média + Exame) / 2 (para alunos com média &lt; 7)<br>
    <?php if ($parametro_atual['permite_exame_oral'] && $parametro_atual['permite_exame_escrito']): ?>
    • Disciplinas de Língua: Média Final = (Média Bimestre + Média Oral/Escrito) / 2<br>
    <?php endif; ?>
    • Aprovação: Média Anual ≥ <?php echo $parametro_atual['nota_minima_aprovacao']; ?><br>
    • Recuperação: Média Anual ≥ <?php echo $parametro_atual['nota_minima_recuperacao']; ?>
</div>

<div class="footer">
    <p>Documento gerado eletronicamente por SIGE Angola - Sistema Integrado de Gestão Escolar</p>
    <p>Este documento é válido para todos os efeitos legais | Emitido em: <?php echo date('d/m/Y H:i:s'); ?></p>
</div>

<table class="signature">
    <tr>
        <td width="33%">
            _________________________________________<br>
            <strong>Diretor Pedagógico</strong>
        </td>
        <td width="33%">
            _________________________________________<br>
            <strong>Coordenador Pedagógico</strong>
        </td>
        <td width="34%">
            _________________________________________<br>
            <strong>Coordenador de Turma</strong>
        </td>
    </tr>
</table>

</body>
</html>

<?php
// Capturar o HTML gerado
$html = ob_get_clean();

// Limpar qualquer saída anterior
if (ob_get_length()) {
    ob_end_clean();
}

// Carregar DOMPDF
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Configurar DOMPDF
$options = new Options();
$options->set('defaultFont', 'DeJaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

// Carregar HTML
$dompdf->loadHtml($html);

// Configurar papel A3 paisagem
$dompdf->setPaper('A3', 'landscape');

// Renderizar PDF
$dompdf->render();

// Enviar para download
$dompdf->stream("pauta_final_{$turma_selecionada}_{$ano_selecionado}.pdf", array("Attachment" => true));
exit;
?>