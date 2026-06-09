<?php
// escola/pedagogico/exportar_pauta_pdf.php - Exportar Pauta Final para PDF

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
$nome_escola = $escola_nome_completo ? $escola_nome_completo['nome'] : 'SIGE Angola';

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
            return 'nota-vermelha';
        } else {
            return 'nota-azul';
        }
    } else {
        if ($nota <= 9.4) {
            return 'nota-vermelha';
        } else {
            return 'nota-azul';
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
            margin: 10mm;
        }
        
        body {
            font-family: "DeJaVu Sans", "Arial", sans-serif;
            font-size: 7pt;
            margin: 0;
            padding: 0;
        }
        
        .cabecario {
            text-align: center;
            font-weight: bold;
            font-size: 9pt;
            margin: 1px 0;
        }
        
        .cabecariodistrito {
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
            margin: 3px 0;
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .cabecariotitulominipauta {
            text-align: center;
            font-weight: bold;
            font-size: 10pt;
            margin: 5px 0 3px 0;
        }
        
        .cabecarioturma {
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
            margin: 3px 0;
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        /* Container principal do rodapé com duas colunas */
        .footer-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-top: 20px;
            width: 100%;
        }
        
        /* Coluna Esquerda - Conselho de Nota */
        .conselho-esquerda {
            width: 45%;
            text-align: left;
        }
        
        .conselho-esquerda .paragrafo {
            text-align: left;
            margin: 5px 0;
        }
        
        /* Coluna Direita - Assinaturas */
        .assinaturas-direita {
            width: 50%;
            text-align: center;
        }
        
        .assinaturas-direita .paragrafo {
            text-align: center;
            margin: 5px 0;
        }
        
        .cabecarioassinatura {
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
            margin-bottom: 15px;
        }
        
        .assinafuncionario1 {
            display: inline-block;
            width: 45%;
            text-align: center;
            margin: 0 10px;
        }
        
        .conselho {
            font-size: 9pt;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 6.5pt;
            margin-top: 5px;
        }
        
        th {
            background: #1e5799;
            color: white;
            padding: 3px;
            text-align: center;
            border: 0.5px solid #ddd;
            font-weight: bold;
        }
        
        td {
            padding: 2px;
            border: 0.5px solid #ddd;
            text-align: center;
            vertical-align: middle;
        }
        
        .text-left {
            text-align: left;
        }
        
        .nota-vermelha { 
            color: #dc3545; 
            font-weight: bold; 
        }
        .nota-azul { 
            color: #0066cc; 
            font-weight: bold; 
        }
    </style>
</head>
<body>

<!-- Cabeçalho -->
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

<!-- Tabela da pauta -->
<table>
    <thead>
        <tr>
            <th rowspan="2">Nº</th>
            <th rowspan="2">Matrícula</th>
            <th rowspan="2">Nome do Aluno</th>
            <?php foreach ($disciplinas as $disc): ?>
                <th colspan="4"><?php echo htmlspecialchars($disc['codigo'] ?: substr($disc['nome'], 0, 3)); ?></th>
            <?php endforeach; ?>
            <th rowspan="2">Média Geral</th>
            <th rowspan="2">Resultado</th>
        </tr>
        <tr>
            <?php foreach ($disciplinas as $disc): ?>
                <th>1º</th>
                <th>2º</th>
                <th>3º</th>
                <th>M</th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php $i = 1; foreach ($alunos as $aluno): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($aluno['matricula'] ?: '-'); ?></td>
                <td class="text-left"><?php echo htmlspecialchars(substr($aluno['nome'], 0, 30)); ?></td>
                
                <?php foreach ($disciplinas as $disc): ?>
                    <?php 
                    $disc_notas = isset($aluno['notas_por_disciplina'][$disc['id']]) ? $aluno['notas_por_disciplina'][$disc['id']] : null;
                    ?>
                    <?php for ($b = 1; $b <= 3; $b++): ?>
                        <td>
                            <?php 
                            if ($disc_notas && isset($disc_notas['bimestres'][$b]) && $disc_notas['bimestres'][$b] !== null) {
                                $nota = $disc_notas['bimestres'][$b];
                                $classe_cor = getCorNota($nota, $is_classe_inferior);
                                echo '<span class="' . $classe_cor . '">' . number_format($nota, 1) . '</span>';
                            } else {
                                echo '<span class="nota-vermelha">0.0</span>';
                            }
                            ?>
                        </td>
                    <?php endfor; ?>
                    <td>
                        <?php 
                        if ($disc_notas) {
                            $media = $disc_notas['media_anual'];
                            $classe_cor = getCorNota($media, $is_classe_inferior);
                            echo '<strong class="' . $classe_cor . '">' . number_format($media, 1) . '</strong>';
                        } else {
                            echo '<strong class="nota-vermelha">0.0</strong>';
                        }
                        ?>
                    </td>
                <?php endforeach; ?>
                
                <td><strong><?php echo number_format($aluno['media_geral'], 1); ?></strong></td>
                <td>
                    <?php 
                    if ($aluno['resultado_geral'] == 'Aprovado') {
                        echo '<span style="color:#0066cc; font-weight:bold;">Aprovado</span>';
                    } elseif ($aluno['resultado_geral'] == 'Recuperação') {
                        echo '<span style="color:#856404; font-weight:bold;">Recuperação</span>';
                    } else {
                        echo '<span style="color:#dc3545; font-weight:bold;">Reprovado</span>';
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Rodapé com Conselho na Esquerda e Assinaturas na Direita -->
<div class='cabecarioassinatura'>
            <?php echo htmlspecialchars($nome_escola); ?>, <?php echo $dia; ?> de <?php echo $mes; ?> de <?php echo $ano; ?>
</div>
<div class="footer-container">
    <!-- Coluna Esquerda - Conselho de Nota -->
    <div class="conselho-esquerda">
        <div class='paragrafo'>
            <b>O CONSELHO DE NOTA</b> 
            <b class='assinafuncionario1'>O(A) SUBDIRECTOR(A) PEDAGOGICO</b>
            <b class='assinafuncionario1'>O(A) DIRECTOR(A)</b>
        </div>
        <div class='paragrafo'>
            <strong class='conselho'>1._____________________________________________</strong>
        </div>
        <div class='paragrafo'>
            <strong class='conselho'>2._____________________________________________</strong>
        </div>
        <div class='paragrafo'>
            <strong class='conselho'>3._____________________________________________</strong>
        </div>
    </div>
    
</div>

</body>
</html>

<?php
$html = ob_get_clean();

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'DeJaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A3', 'landscape');
$dompdf->render();
$dompdf->stream("pauta_final_{$turma_selecionada}_{$ano_selecionado}.pdf", array("Attachment" => true));
exit;
?>