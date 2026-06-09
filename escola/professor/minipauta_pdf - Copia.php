<?php
// escola/professor/minipauta_pdf.php - Mini Pauta em PDF

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

if (!$turma_id || !$disciplina_id) {
    die('Parâmetros inválidos. Selecione uma turma e disciplina.');
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_atual = $ano_letivo['ano'] ?? date('Y') . '/' . (date('Y') + 1);

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    $escola = ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
}

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "SELECT id, nome, ano, turno, sala FROM turmas WHERE id = :turma_id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada.');
}

$classe_ano = $turma['ano'] ?? 0;
$is_ensino_fundamental = ($classe_ano <= 6);
$is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
$limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
$max_nota = $is_ensino_fundamental ? 10 : 20;

// ============================================
// BUSCAR DADOS DA DISCIPLINA
// ============================================
$sql_disciplina = "SELECT id, nome, codigo FROM disciplinas WHERE id = :disciplina_id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':disciplina_id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);
$disciplina_nome = $disciplina['nome'] ?? 'Disciplina';

$is_disciplina_lingua = (stripos($disciplina_nome, 'português') !== false || stripos($disciplina_nome, 'inglês') !== false);

// ============================================
// BUSCAR ALUNOS E NOTAS
// ============================================
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.foto
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':turma_id' => $turma_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
$total_alunos = count($alunos);

// Buscar notas existentes
$notas_existentes = [];
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
        status
    FROM notas
    WHERE disciplina_id = :disciplina_id 
    AND bimestre = :bimestre 
    AND ano_letivo_id = :ano_letivo_id
";
$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([
    ':disciplina_id' => $disciplina_id,
    ':bimestre' => $bimestre,
    ':ano_letivo_id' => $ano_letivo_id
]);
while ($row = $stmt_notas->fetch(PDO::FETCH_ASSOC)) {
    $notas_existentes[$row['estudante_id']] = $row;
}

// ============================================
// CALCULAR ESTATÍSTICAS
// ============================================
function calcularEstatisticasPDF($alunos, $notas_existentes, $limite_aprovacao) {
    $total_aprovados = 0;
    $total_recuperacao = 0;
    $total_reprovados = 0;
    $soma_medias = 0;
    $count_com_nota = 0;
    $maior_nota = 0;
    $menor_nota = 100;
    
    foreach ($alunos as $aluno) {
        $nota = $notas_existentes[$aluno['id']] ?? null;
        $media = $nota['media_final'] ?? 0;
        
        if ($media > 0) {
            if ($media > $limite_aprovacao) $total_aprovados++;
            elseif ($media == $limite_aprovacao) $total_recuperacao++;
            elseif ($media < $limite_aprovacao) $total_reprovados++;
            
            $soma_medias += $media;
            $count_com_nota++;
            if ($media > $maior_nota) $maior_nota = $media;
            if ($media < $menor_nota) $menor_nota = $media;
        }
    }
    
    $media_geral = $count_com_nota > 0 ? round($soma_medias / $count_com_nota, 1) : 0;
    $percentual_aprovacao = count($alunos) > 0 ? round(($total_aprovados / count($alunos)) * 100, 1) : 0;
    
    return [
        'total_aprovados' => $total_aprovados,
        'total_recuperacao' => $total_recuperacao,
        'total_reprovados' => $total_reprovados,
        'media_geral' => $media_geral,
        'maior_nota' => $maior_nota,
        'menor_nota' => $menor_nota == 100 ? 0 : $menor_nota,
        'percentual_aprovacao' => $percentual_aprovacao
    ];
}

$stats = calcularEstatisticasPDF($alunos, $notas_existentes, $limite_aprovacao);

// Função para formatar moeda
function formatarMoedaPDF($valor) {
    return number_format($valor, 2, ',', '.');
}

// Gerar código de autenticação
$codigo_autenticacao = strtoupper(substr(md5($turma_id . $disciplina_id . $bimestre . date('Ymd')), 0, 16));

// Nome do bimestre
$bimestre_nome = '';
switch($bimestre) {
    case 1: $bimestre_nome = '1º Bimestre'; break;
    case 2: $bimestre_nome = '2º Bimestre'; break;
    case 3: $bimestre_nome = '3º Bimestre'; break;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Mini Pauta - <?php echo $disciplina_nome; ?> - <?php echo $bimestre_nome; ?></title>
    <style>
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            background: white;
            padding: 0;
            margin: 0;
            width: 210mm;
            min-height: 297mm;
        }
        
        .relatorio-container {
            max-width: 190mm;
            margin: 0 auto;
            background: white;
            padding: 5mm 0;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 8mm;
            margin-bottom: 10mm;
        }
        
        .header h1 {
            color: #006B3E;
            font-size: 18pt;
            margin-bottom: 3mm;
        }
        
        .header .subtitle {
            font-size: 9pt;
            color: #666;
            line-height: 1.4;
        }
        
        .titulo-relatorio {
            background: #006B3E;
            color: white;
            text-align: center;
            padding: 5mm;
            font-size: 14pt;
            font-weight: bold;
            margin: 8mm 0;
            border-radius: 3mm;
        }
        
        .info-section {
            margin-bottom: 8mm;
            border: 1px solid #ddd;
            border-radius: 3mm;
            overflow: hidden;
        }
        
        .info-title {
            background: #f5f5f5;
            padding: 4mm 5mm;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            font-size: 11pt;
        }
        
        .info-content {
            padding: 5mm;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 3mm;
            flex-wrap: wrap;
        }
        
        .info-label {
            width: 40mm;
            font-weight: bold;
            color: #555;
            font-size: 10pt;
        }
        
        .info-value {
            flex: 1;
            color: #333;
            font-size: 10pt;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4mm;
            margin-bottom: 8mm;
        }
        
        .stat-card {
            border: 1px solid #ddd;
            border-radius: 3mm;
            padding: 4mm;
            text-align: center;
            background: #f9f9f9;
        }
        
        .stat-card.aprovados { border-top: 2mm solid #28a745; }
        .stat-card.recuperacao { border-top: 2mm solid #ffc107; }
        .stat-card.reprovados { border-top: 2mm solid #dc3545; }
        .stat-card.media { border-top: 2mm solid #4361ee; }
        
        .stat-value {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 2mm;
        }
        
        .stat-label {
            font-size: 8pt;
            color: #666;
        }
        
        .tabela-notas {
            width: 100%;
            border-collapse: collapse;
            margin: 5mm 0;
            font-size: 9pt;
        }
        
        .tabela-notas th, 
        .tabela-notas td {
            border: 1px solid #ddd;
            padding: 3mm 2mm;
            text-align: center;
        }
        
        .tabela-notas th {
            background: #f5f5f5;
            font-weight: bold;
            font-size: 9pt;
        }
        
        .tabela-notas td {
            font-size: 9pt;
        }
        
        .text-start {
            text-align: left;
        }
        
        .text-end {
            text-align: right;
        }
        
        .status-aprovado {
            color: #28a745;
            font-weight: bold;
        }
        
        .status-recuperacao {
            color: #ffc107;
            font-weight: bold;
        }
        
        .status-reprovado {
            color: #dc3545;
            font-weight: bold;
        }
        
        .status-pendente {
            color: #6c757d;
            font-weight: bold;
        }
        
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin-top: 15mm;
            padding-top: 10mm;
        }
        
        .assinatura {
            text-align: center;
            width: 60mm;
        }
        
        .assinatura-linha {
            border-top: 0.3mm solid #333;
            margin-top: 12mm;
            padding-top: 2mm;
            width: 100%;
        }
        
        .assinatura div {
            font-size: 9pt;
            margin-top: 2mm;
        }
        
        .footer {
            margin-top: 10mm;
            text-align: center;
            font-size: 8pt;
            color: #999;
            border-top: 0.2mm solid #ddd;
            padding-top: 5mm;
        }
        
        .codigo-autenticacao {
            font-family: 'Courier New', monospace;
            font-size: 10pt;
            background: #f0f0f0;
            padding: 3mm 5mm;
            border-radius: 3mm;
            text-align: center;
            display: inline-block;
        }
        
        .legenda {
            font-size: 8pt;
            margin-top: 5mm;
            padding: 4mm;
            background: #f8f9fa;
            border-radius: 3mm;
        }
        
        .legenda span {
            display: inline-block;
            margin-right: 8mm;
        }
        
        .fw-bold {
            font-weight: bold;
        }
        
        @media print {
            body {
                width: 210mm;
                min-height: 297mm;
                padding: 0;
                margin: 0;
            }
            
            .relatorio-container {
                max-width: 190mm;
                margin: 0 auto;
                padding: 0;
            }
            
            .no-print {
                display: none;
            }
            
            .info-section, 
            .stats-grid, 
            .tabela-notas,
            .assinaturas,
            .footer {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            
            .tabela-notas tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
        
        @media screen {
            body {
                background: #e0e0e0;
                padding: 20px;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                width: 100%;
            }
            
            .relatorio-container {
                box-shadow: 0 5px 20px rgba(0,0,0,0.1);
                margin: 20px auto;
                padding: 10mm;
            }
        }
        
        .text-success { color: #28a745; }
        .text-danger { color: #dc3545; }
        .text-warning { color: #ffc107; }
        .text-primary { color: #4361ee; }
        
        .btn-print {
            background: #006B3E;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 10px 5px;
            font-size: 12px;
        }
        
        .btn-print:hover {
            background: #004d2e;
        }
    </style>
</head>
<body>
<div class="relatorio-container">
    <div class="header">
        <h1><?php echo htmlspecialchars($escola['nome']); ?></h1>
        <div class="subtitle">
            <?php echo htmlspecialchars($escola['endereco']); ?><br>
            NIF: <?php echo htmlspecialchars($escola['nif']); ?> | 
            Tel: <?php echo htmlspecialchars($escola['telefone']); ?> |
            Email: <?php echo htmlspecialchars($escola['email']); ?>
        </div>
    </div>
    
    <div class="titulo-relatorio">
        MINI PAUTA - <?php echo $bimestre_nome; ?>
    </div>
    
    <!-- Informações da Turma e Disciplina -->
    <div class="info-section">
        <div class="info-title">INFORMAÇÕES DA AVALIAÇÃO</div>
        <div class="info-content">
            <div class="info-row">
                <div class="info-label">Turma:</div>
                <div class="info-value"><?php echo $turma['ano'] . 'ª Classe - ' . htmlspecialchars($turma['nome']) . ' (' . ucfirst($turma['turno']) . ')'; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Disciplina:</div>
                <div class="info-value"><?php echo htmlspecialchars($disciplina_nome); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Professor(a):</div>
                <div class="info-value"><?php echo htmlspecialchars($professor['professor_nome']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Ano Letivo:</div>
                <div class="info-value"><?php echo $ano_letivo_atual; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Escala de Avaliação:</div>
                <div class="info-value"><?php echo $is_ensino_fundamental ? '0-10' : '0-20'; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Data de Emissão:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i:s'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card aprovados">
            <div class="stat-value text-success"><?php echo $stats['total_aprovados']; ?></div>
            <div class="stat-label">Aprovados</div>
            <small><?php echo $stats['percentual_aprovacao']; ?>%</small>
        </div>
        <div class="stat-card recuperacao">
            <div class="stat-value text-warning"><?php echo $stats['total_recuperacao']; ?></div>
            <div class="stat-label">Recuperação</div>
        </div>
        <div class="stat-card reprovados">
            <div class="stat-value text-danger"><?php echo $stats['total_reprovados']; ?></div>
            <div class="stat-label">Reprovados</div>
        </div>
        <div class="stat-card media">
            <div class="stat-value text-primary"><?php echo $stats['media_geral']; ?></div>
            <div class="stat-label">Média Geral</div>
        </div>
    </div>
    
    <!-- Tabela de Notas -->
    <div class="info-section">
        <div class="info-title">NOTAS DOS ALUNOS</div>
        <div class="info-content">
            <table class="tabela-notas">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="30%">Nome do Aluno</th>
                        <th width="15%">Matrícula</th>
                        <?php if ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua): ?>
                        <th width="10%">MAC</th>
                        <th width="10%">Exame Normal</th>
                        <th width="10%">Exame Recurso</th>
                        <?php elseif ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua): ?>
                        <th width="8%">MAC</th>
                        <th width="8%">Exame Oral</th>
                        <th width="8%">Exame Escrito</th>
                        <th width="8%">Exame Recurso</th>
                        <?php elseif ($bimestre == 3): ?>
                        <th width="10%">MAC</th>
                        <th width="10%">NPT</th>
                        <th width="10%">Exame</th>
                        <?php else: ?>
                        <th width="10%">MAC</th>
                        <th width="10%">NPT</th>
                        <?php endif; ?>
                        <th width="10%">Média</th>
                        <th width="12%">Situação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alunos as $index => $aluno):
                        $nota = $notas_existentes[$aluno['id']] ?? null;
                        $media_final = $nota['media_final'] ?? 0;
                        $status = $nota['status'] ?? 'pendente';
                        
                        $status_class = '';
                        $status_texto = '';
                        if ($status == 'aprovado') {
                            $status_class = 'status-aprovado';
                            $status_texto = 'APROVADO';
                        } elseif ($status == 'recuperacao') {
                            $status_class = 'status-recuperacao';
                            $status_texto = 'RECUPERAÇÃO';
                        } elseif ($status == 'reprovado') {
                            $status_class = 'status-reprovado';
                            $status_texto = 'REPROVADO';
                        } else {
                            $status_class = 'status-pendente';
                            $status_texto = 'PENDENTE';
                        }
                        
                        $media_color = '';
                        if ($media_final > 0) {
                            if ($media_final > $limite_aprovacao) $media_color = 'status-aprovado';
                            elseif ($media_final == $limite_aprovacao) $media_color = 'status-recuperacao';
                            elseif ($media_final < $limite_aprovacao) $media_color = 'status-reprovado';
                        }
                    ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td class="text-start"><?php echo strtoupper(htmlspecialchars($aluno['nome'])); ?></td>
                        <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                        
                        <?php if ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua): ?>
                        <td><?php echo $nota['mac'] ?? '-'; ?></td>
                        <td><?php echo $nota['exame_normal'] ?? '-'; ?></td>
                        <td><?php echo $nota['exame_recurso'] ?? '-'; ?></td>
                        <?php elseif ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua): ?>
                        <td><?php echo $nota['mac'] ?? '-'; ?></td>
                        <td><?php echo $nota['exame_oral'] ?? '-'; ?></td>
                        <td><?php echo $nota['exame_escrito'] ?? '-'; ?></td>
                        <td><?php echo $nota['exame_recurso'] ?? '-'; ?></td>
                        <?php elseif ($bimestre == 3): ?>
                        <td><?php echo $nota['mac'] ?? '-'; ?></td>
                        <td><?php echo $nota['npt'] ?? '-'; ?></td>
                        <td><?php echo $nota['exame_normal'] ?? '-'; ?></td>
                        <?php else: ?>
                        <td><?php echo $nota['mac'] ?? '-'; ?></td>
                        <td><?php echo $nota['npt'] ?? '-'; ?></td>
                        <?php endif; ?>
                        
                        <td class="<?php echo $media_color; ?> fw-bold"><?php echo number_format($media_final, 1); ?></td>
                        <td class="<?php echo $status_class; ?>"><?php echo $status_texto; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f0f0f0;">
                        <td colspan="<?php echo ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua) ? '6' : (($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua) ? '7' : (($bimestre == 3) ? '5' : '4')); ?>" class="text-end"><strong>TOTAIS:</strong></td>
                        <td><strong><?php echo $stats['total_aprovados']; ?> Aprovados</strong></td>
                        <td><strong><?php echo $stats['total_recuperacao']; ?> Recup.</strong></td>
                        <td><strong><?php echo $stats['total_reprovados']; ?> Reprov.</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Resumo Estatístico -->
    <div class="info-section">
        <div class="info-title">RESUMO ESTATÍSTICO</div>
        <div class="info-content">
            <div class="info-row">
                <div class="info-label">Total de Alunos:</div>
                <div class="info-value"><?php echo $total_alunos; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Média Geral da Turma:</div>
                <div class="info-value"><?php echo $stats['media_geral']; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Maior Nota:</div>
                <div class="info-value"><?php echo $stats['maior_nota']; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Menor Nota:</div>
                <div class="info-value"><?php echo $stats['menor_nota']; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Percentual de Aprovação:</div>
                <div class="info-value"><?php echo $stats['percentual_aprovacao']; ?>%</div>
            </div>
        </div>
    </div>
    
    <!-- Legenda -->
    <div class="legenda">
        <strong>Legenda de Cores:</strong>
        <span><span class="status-aprovado">●</span> APROVADO - Nota > <?php echo $limite_aprovacao; ?></span>
        <span><span class="status-recuperacao">●</span> RECUPERAÇÃO - Nota = <?php echo $limite_aprovacao; ?></span>
        <span><span class="status-reprovado">●</span> REPROVADO - Nota < <?php echo $limite_aprovacao; ?></span>
        <span><span class="status-pendente">●</span> PENDENTE - Nota não lançada</span>
    </div>
    
    <!-- Código de Autenticação -->
    <div style="text-align: center; margin: 20px 0;">
        <div class="codigo-autenticacao">
            <strong>Código de Autenticação:</strong> <?php echo $codigo_autenticacao; ?>
        </div>
        <small>Consulte a autenticidade deste documento na secretaria escolar</small>
    </div>
    
    <!-- Assinaturas -->
    <div class="assinaturas">
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div>Professor(a)</div>
            <div><?php echo htmlspecialchars($professor['professor_nome']); ?></div>
        </div>
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div>Coordenador Pedagógico</div>
        </div>
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div>Direção da Escola</div>
        </div>
    </div>
    
    <!-- Rodapé -->
    <div class="footer">
        <p>Documento emitido eletronicamente por SIGE Angola - Sistema de Gestão Escolar</p>
        <p><?php echo $disciplina_nome; ?> - <?php echo $bimestre_nome; ?> - Turma: <?php echo $turma['ano'] . 'ª ' . $turma['nome']; ?></p>
        <p>Data e hora da emissão: <?php echo date('d/m/Y H:i:s'); ?></p>
    </div>
</div>

<div class="no-print" style="text-align: center; margin-top: 20px;">
    <button class="btn-print" onclick="window.print();">
        🖨️ Imprimir
    </button>
    <button class="btn-print" onclick="baixarPDF();" style="background: #dc3545;">
        📄 Baixar PDF
    </button>
    <button class="btn-print" onclick="window.history.back();" style="background: #6c757d;">
        ↩️ Voltar
    </button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
function baixarPDF() {
    const element = document.querySelector('.relatorio-container');
    
    Swal.fire({
        title: 'Gerando PDF...',
        text: 'Aguarde enquanto a mini pauta está sendo gerada',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const opt = {
        margin: [0.5, 0.5, 0.5, 0.5],
        filename: `minipauta_${new Date().toISOString().slice(0,10)}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, letterRendering: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };
    
    html2pdf().set(opt).from(element).save().then(() => {
        Swal.fire({
            icon: 'success',
            title: 'PDF Gerado!',
            text: 'O download foi iniciado com sucesso',
            timer: 2000,
            showConfirmButton: false
        });
    }).catch((error) => {
        Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: 'Erro ao gerar PDF. Tente novamente.',
            confirmButtonColor: '#dc3545'
        });
    });
}
</script>
</body>
</html>