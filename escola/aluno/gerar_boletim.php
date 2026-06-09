<?php
// escola/aluno/academico/gerar_boletim.php - Gerar Boletim para Visualização/Impressão

require_once __DIR__ . '/../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die('Acesso negado.');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Parâmetros
$ano_letivo_id = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
$turma_id = $turma['id'] ?? 0;

// Buscar anolectivo
$sql_ano_letivo= "SELECT id,ano 
              FROM ano_letivo
              WHERE id= :ano_letivo_id AND ativo =1 and escola_id=:escola_id";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([
    ':ano_letivo_id' => $ano_letivo_id,
    ':escola_id' => $escola_id
    ]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo = $ano_letivo['ano'] ?? 0;

// Buscar informações da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    $escola = ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
}

// Buscar disciplinas do aluno
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo, d.carga_horaria
                    FROM disciplinas d
                    JOIN disciplina_turma dt ON dt.disciplina_id = d.id
                    WHERE dt.turma_id = :turma_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':turma_id' => $turma_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar notas do aluno
$sql_notas = "SELECT 
                    n.id,
                    n.disciplina_id,
                    n.bimestre,
                    n.mac,
                    n.npt,
                    n.exame_normal,
                    n.exame_recurso,
                    n.exame_especial,
                    n.exame_oral,
                    n.exame_escrito,
                    n.media_parcial,
                    n.media_final,
                    n.status,
                    d.nome as disciplina_nome
              FROM notas n
              JOIN disciplinas d ON d.id = n.disciplina_id
              WHERE n.estudante_id = :aluno_id 
              AND n.escola_id = :escola_id
              AND n.ano_letivo_id = :ano";

if ($bimestre_filtro > 0) {
    $sql_notas .= " AND n.bimestre = :bimestre";
}

$sql_notas .= " ORDER BY d.nome ASC, n.bimestre ASC";

$stmt_notas = $conn->prepare($sql_notas);
$params = [
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':ano' => $ano_letivo_id
];
if ($bimestre_filtro > 0) {
    $params[':bimestre'] = $bimestre_filtro;
}
$stmt_notas->execute($params);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// Processar notas por disciplina
$boletim = [];
foreach ($disciplinas as $disciplina) {
    $boletim[$disciplina['id']] = [
        'disciplina_nome' => $disciplina['nome'],
        'bimestres' => [
            1 => ['mac' => null, 'npt' => null, 'media_parcial' => null],
            2 => ['mac' => null, 'npt' => null, 'media_parcial' => null],
            3 => ['mac' => null, 'npt' => null, 'media_parcial' => null],
            4 => ['mac' => null, 'npt' => null, 'media_parcial' => null]
        ],
        'exame_normal' => null,
        'exame_recurso' => null,
        'exame_especial' => null,
        'media_final' => null,
        'status' => null
    ];
}

foreach ($notas as $nota) {
    $disc_id = $nota['disciplina_id'];
    $bimestre = $nota['bimestre'];
    
    if (isset($boletim[$disc_id])) {
        $boletim[$disc_id]['bimestres'][$bimestre] = [
            'mac' => $nota['mac'],
            'npt' => $nota['npt'],
            'media_parcial' => $nota['media_parcial']
        ];
        $boletim[$disc_id]['exame_normal'] = $nota['exame_normal'];
        $boletim[$disc_id]['exame_recurso'] = $nota['exame_recurso'];
        $boletim[$disc_id]['exame_especial'] = $nota['exame_especial'];
        $boletim[$disc_id]['media_final'] = $nota['media_final'];
        $boletim[$disc_id]['status'] = $nota['status'];
    }
}

// Calcular médias
$media_geral = 0;
$total_medias = 0;
foreach ($boletim as $dados) {
    if ($dados['media_final'] !== null) {
        $media_geral += $dados['media_final'];
        $total_medias++;
    }
}
$media_geral = $total_medias > 0 ? round($media_geral / $total_medias, 1) : 0;

function getSituacaoTexto($status) {
    switch ($status) {
        case 'aprovado': return 'Aprovado';
        case 'reprovado': return 'Reprovado';
        case 'recuperacao': return 'Recuperação';
        default: return 'Pendente';
    }
}

function getCorSituacao($status) {
    switch ($status) {
        case 'aprovado': return '#28a745';
        case 'reprovado': return '#dc3545';
        case 'recuperacao': return '#ffc107';
        default: return '#6c757d';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boletim Escolar - <?php echo $aluno_nome; ?> - <?php echo $ano_letivo; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', 'Arial', sans-serif;
            background: #e0e0e0;
            padding: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .boletim-container {
            max-width: 1200px;
            width: 100%;
            background: white;
            margin: 0 auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .boletim {
            padding: 30px;
        }
        
        /* Cabeçalho */
        .header {
            text-align: center;
            border-bottom: 3px solid #006B3E;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        
        .header h1 {
            color: #006B3E;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 12px;
            color: #666;
        }
        
        .titulo-boletim {
            background: #006B3E;
            color: white;
            text-align: center;
            padding: 10px;
            font-size: 18px;
            font-weight: bold;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        /* Informações do Aluno */
        .info-aluno {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        
        .info-label {
            width: 120px;
            font-weight: bold;
            color: #555;
        }
        
        .info-value {
            flex: 1;
            color: #333;
        }
        
        /* Tabela de Notas */
        .notas-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 12px;
        }
        
        .notas-table th, .notas-table td {
            border: 1px solid #ddd;
            padding: 10px 6px;
            text-align: center;
            vertical-align: middle;
        }
        
        .notas-table th {
            background: #f5f5f5;
            font-weight: bold;
        }
        
        .notas-table .disciplina-cell {
            text-align: left;
            font-weight: bold;
        }
        
        /* Rodapé */
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        
        /* Assinaturas */
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 20px;
        }
        
        .assinatura {
            text-align: center;
            width: 250px;
        }
        
        .assinatura-linha {
            border-top: 1px solid #333;
            margin-top: 40px;
            padding-top: 5px;
        }
        
        /* Botões */
        .botoes {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #ddd;
        }
        
        .btn {
            padding: 10px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 5px;
        }
        
        .btn-imprimir {
            background: #17a2b8;
            color: white;
        }
        
        .btn-imprimir:hover {
            background: #138496;
        }
        
        .btn-fechar {
            background: #6c757d;
            color: white;
        }
        
        .btn-fechar:hover {
            background: #5a6268;
        }
        
        /* Status */
        .status-aprovado { color: #28a745; font-weight: bold; }
        .status-reprovado { color: #dc3545; font-weight: bold; }
        .status-recuperacao { color: #ffc107; font-weight: bold; }
        
        /* Cores das notas */
        .nota-alta { color: #28a745; font-weight: bold; }
        .nota-media { color: #ffc107; font-weight: bold; }
        .nota-baixa { color: #dc3545; font-weight: bold; }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .boletim-container {
                box-shadow: none;
                margin: 0;
            }
            .botoes {
                display: none;
            }
            .boletim {
                padding: 20px;
            }
        }
        
        .media-geral {
            background: #006B3E;
            color: white;
            padding: 10px;
            text-align: center;
            border-radius: 5px;
            margin-top: 15px;
        }
        
        .legenda {
            font-size: 10px;
            margin-top: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .legenda span {
            display: inline-block;
            margin-right: 15px;
        }
        
        .exames-info {
            font-size: 11px;
            margin-top: 10px;
            padding: 8px;
            background: #e8f5e9;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_aluno.php'; ?>
<div class="boletim-container">
    <div class="boletim">
        <!-- Cabeçalho -->
        <div class="header">
            <h1><?php echo htmlspecialchars($escola['nome']); ?></h1>
            <div class="subtitle">
                <?php echo htmlspecialchars($escola['endereco']); ?><br>
                NIF: <?php echo htmlspecialchars($escola['nif']); ?> | 
                Tel: <?php echo htmlspecialchars($escola['telefone']); ?>
            </div>
            <div class="titulo-boletim">
                BOLETIM ESCOLAR - <?php echo $ano_letivo; ?>
                <?php if ($bimestre_filtro > 0): ?>
                - <?php echo $bimestre_filtro; ?>º BIMESTRE
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="info-aluno">
            <div class="info-row">
                <div class="info-label">Aluno:</div>
                <div class="info-value"><?php echo strtoupper(htmlspecialchars($aluno_nome)); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Matrícula:</div>
                <div class="info-value"><?php echo $aluno_matricula; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Turma:</div>
                <div class="info-value"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Ano Letivo:</div>
                <div class="info-value"><?php echo $ano_letivo; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Data de Emissão:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i:s'); ?></div>
            </div>
        </div>
        
        <!-- Tabela de Notas -->
        <table class="notas-table">
            <thead>
                <tr>
                    <th rowspan="2">Disciplina</th>
                    <?php if ($bimestre_filtro == 0): ?>
                    <th colspan="3" class="text-center">1º Bimestre</th>
                    <th colspan="3" class="text-center">2º Bimestre</th>
                    <th colspan="3" class="text-center">3º Bimestre</th>
                    <th colspan="3" class="text-center">4º Bimestre</th>
                    <th rowspan="2">Exame</th>
                    <th rowspan="2">Média<br>Final</th>
                    <th rowspan="2">Situação</th>
                    <?php else: ?>
                    <th>MAC</th>
                    <th>NPT</th>
                    <th>Média<br>Parcial</th>
                    <th>Exame</th>
                    <th>Média<br>Final</th>
                    <th>Situação</th>
                    <?php endif; ?>
                </tr>
                <?php if ($bimestre_filtro == 0): ?>
                <tr>
                    <th>MAC</th><th>NPT</th><th>Méd.</th>
                    <th>MAC</th><th>NPT</th><th>Méd.</th>
                    <th>MAC</th><th>NPT</th><th>Méd.</th>
                    <th>MAC</th><th>NPT</th><th>Méd.</th>
                </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php foreach ($boletim as $dados): 
                    $status_class = '';
                    if ($dados['status'] == 'aprovado') $status_class = 'status-aprovado';
                    elseif ($dados['status'] == 'reprovado') $status_class = 'status-reprovado';
                    elseif ($dados['status'] == 'recuperacao') $status_class = 'status-recuperacao';
                    
                    function getNotaClass($nota) {
                        if ($nota === null) return '';
                        if ($nota >= 14) return 'nota-alta';
                        if ($nota >= 10) return 'nota-media';
                        return 'nota-baixa';
                    }
                ?>
                <tr>
                    <td class="disciplina-cell"><?php echo htmlspecialchars($dados['disciplina_nome']); ?></td>
                    
                    <?php if ($bimestre_filtro == 0): ?>
                        <!-- 1º Bimestre -->
                        <td class="<?php echo getNotaClass($dados['bimestres'][1]['mac']); ?>">
                            <?php echo $dados['bimestres'][1]['mac'] !== null ? number_format($dados['bimestres'][1]['mac'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][1]['npt']); ?>">
                            <?php echo $dados['bimestres'][1]['npt'] !== null ? number_format($dados['bimestres'][1]['npt'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][1]['media_parcial']); ?>">
                            <?php echo $dados['bimestres'][1]['media_parcial'] !== null ? number_format($dados['bimestres'][1]['media_parcial'], 1, ',', '.') : '-'; ?>
                        </td>
                        
                        <!-- 2º Bimestre -->
                        <td class="<?php echo getNotaClass($dados['bimestres'][2]['mac']); ?>">
                            <?php echo $dados['bimestres'][2]['mac'] !== null ? number_format($dados['bimestres'][2]['mac'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][2]['npt']); ?>">
                            <?php echo $dados['bimestres'][2]['npt'] !== null ? number_format($dados['bimestres'][2]['npt'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][2]['media_parcial']); ?>">
                            <?php echo $dados['bimestres'][2]['media_parcial'] !== null ? number_format($dados['bimestres'][2]['media_parcial'], 1, ',', '.') : '-'; ?>
                        </td>
                        
                        <!-- 3º Bimestre -->
                        <td class="<?php echo getNotaClass($dados['bimestres'][3]['mac']); ?>">
                            <?php echo $dados['bimestres'][3]['mac'] !== null ? number_format($dados['bimestres'][3]['mac'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][3]['npt']); ?>">
                            <?php echo $dados['bimestres'][3]['npt'] !== null ? number_format($dados['bimestres'][3]['npt'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][3]['media_parcial']); ?>">
                            <?php echo $dados['bimestres'][3]['media_parcial'] !== null ? number_format($dados['bimestres'][3]['media_parcial'], 1, ',', '.') : '-'; ?>
                        </td>
                        
                        <!-- 4º Bimestre -->
                        <td class="<?php echo getNotaClass($dados['bimestres'][4]['mac']); ?>">
                            <?php echo $dados['bimestres'][4]['mac'] !== null ? number_format($dados['bimestres'][4]['mac'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][4]['npt']); ?>">
                            <?php echo $dados['bimestres'][4]['npt'] !== null ? number_format($dados['bimestres'][4]['npt'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][4]['media_parcial']); ?>">
                            <?php echo $dados['bimestres'][4]['media_parcial'] !== null ? number_format($dados['bimestres'][4]['media_parcial'], 1, ',', '.') : '-'; ?>
                        </td>
                        
                        <!-- Exames e Média Final -->
                        <td>
                            <?php 
                            $exames = [];
                            if ($dados['exame_normal'] !== null) $exames[] = 'N: ' . number_format($dados['exame_normal'], 1, ',', '.');
                            if ($dados['exame_recurso'] !== null) $exames[] = 'R: ' . number_format($dados['exame_recurso'], 1, ',', '.');
                            if ($dados['exame_especial'] !== null) $exames[] = 'E: ' . number_format($dados['exame_especial'], 1, ',', '.');
                            echo !empty($exames) ? implode('<br>', $exames) : '-';
                            ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['media_final']); ?>">
                            <?php echo $dados['media_final'] !== null ? number_format($dados['media_final'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo $status_class; ?>">
                            <?php echo getSituacaoTexto($dados['status']); ?>
                        </td>
                        
                    <?php else: ?>
                        <!-- Bimestre específico -->
                        <?php $b = $bimestre_filtro; ?>
                        <td class="<?php echo getNotaClass($dados['bimestres'][$b]['mac']); ?>">
                            <?php echo $dados['bimestres'][$b]['mac'] !== null ? number_format($dados['bimestres'][$b]['mac'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][$b]['npt']); ?>">
                            <?php echo $dados['bimestres'][$b]['npt'] !== null ? number_format($dados['bimestres'][$b]['npt'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['bimestres'][$b]['media_parcial']); ?>">
                            <?php echo $dados['bimestres'][$b]['media_parcial'] !== null ? number_format($dados['bimestres'][$b]['media_parcial'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td>
                            <?php 
                            $exames = [];
                            if ($dados['exame_normal'] !== null) $exames[] = 'N: ' . number_format($dados['exame_normal'], 1, ',', '.');
                            if ($dados['exame_recurso'] !== null) $exames[] = 'R: ' . number_format($dados['exame_recurso'], 1, ',', '.');
                            if ($dados['exame_especial'] !== null) $exames[] = 'E: ' . number_format($dados['exame_especial'], 1, ',', '.');
                            echo !empty($exames) ? implode('<br>', $exames) : '-';
                            ?>
                        </td>
                        <td class="<?php echo getNotaClass($dados['media_final']); ?>">
                            <?php echo $dados['media_final'] !== null ? number_format($dados['media_final'], 1, ',', '.') : '-'; ?>
                        </td>
                        <td class="<?php echo $status_class; ?>">
                            <?php echo getSituacaoTexto($dados['status']); ?>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Média Geral -->
        <div class="media-geral">
            <strong>MÉDIA GERAL DO ALUNO: <?php echo number_format($media_geral, 1, ',', '.'); ?> pontos</strong>
        </div>
        
        <!-- Exames -->
        <div class="exames-info">
            <strong>Legenda de Exames:</strong> N = Exame Normal | R = Exame de Recurso | E = Exame Especial
        </div>
        
        <!-- Legenda -->
        <div class="legenda">
            <strong>Legenda de Cores:</strong>
            <span style="color: #28a745;">● Nota ≥ 14 (Excelente)</span>
            <span style="color: #ffc107;">● Nota entre 10 e 13 (Regular)</span>
            <span style="color: #dc3545;">● Nota &lt; 10 (Insuficiente)</span>
            <span style="color: #28a745;">● Aprovado</span>
            <span style="color: #ffc107;">● Recuperação</span>
            <span style="color: #dc3545;">● Reprovado</span>
        </div>
        
        <!-- Assinaturas -->
        <div class="assinaturas">
            <div class="assinatura">
                <div class="assinatura-linha"></div>
                <div>Aluno / Responsável</div>
            </div>
            <div class="assinatura">
                <div class="assinatura-linha"></div>
                <div>Secretário Escolar</div>
            </div>
            <div class="assinatura">
                <div class="assinatura-linha"></div>
                <div>Diretor Pedagógico</div>
            </div>
        </div>
        
        <!-- Rodapé -->
        <div class="footer">
            <p>Documento emitido eletronicamente por SIGE Angola - Sistema de Gestão Escolar</p>
            <p>Este documento é válido em todo território nacional</p>
        </div>
    </div>
    
    <!-- Botões -->
    <div class="botoes">
        <button class="btn btn-imprimir" onclick="window.print();">
            <i class="fas fa-print"></i> Imprimir / Salvar PDF
        </button>
        <button class="btn btn-fechar" onclick="window.close();">
            <i class="fas fa-times"></i> Fechar
        </button>
    </div>
</div>

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</body>
</html>