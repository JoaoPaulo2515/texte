<?php
// escola/professor/provas/exportar_resultados.php - Exportar Resultados da Prova

require_once __DIR__ . '/../../../config/database.php';
session_start();
/*
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}
*/
$db = Database::getInstance();
$conn = $db->getConnection();
$usuario_id = $_SESSION['usuario_id'];
$escola_id = $_SESSION['escola_id'];

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$apenas_finalizadas = isset($_GET['finalizadas']) ? (int)$_GET['finalizadas'] : 1;
$busca_aluno = isset($_GET['busca']) ? $_GET['busca'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'html'; // html, pdf, excel

// Buscar dados da prova
$sql_prova = "SELECT p.titulo, p.nota_maxima, p.nota_minima_aprovacao, p.duracao_minutos,
                     d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    die('Prova não encontrada');
}

// Buscar resultados
$sql_resultados = "SELECT 
                        e.nome as aluno_nome,
                        e.matricula as aluno_matricula,
                        t.tentativa_numero,
                        t.data_inicio,
                        t.data_fim,
                        t.data_entrega,
                        t.tempo_gasto_segundos,
                        t.pontuacao_total,
                        t.porcentagem,
                        t.aprovado,
                        t.status
                    FROM online_provas_tentativas t
                    JOIN estudantes e ON e.id = t.aluno_id
                    WHERE t.prova_id = :prova_id";

if ($apenas_finalizadas == 1) {
    $sql_resultados .= " AND t.status = 'finalizada'";
}
if (!empty($busca_aluno)) {
    $sql_resultados .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca)";
}

$sql_resultados .= " ORDER BY t.pontuacao_total DESC";

$stmt_resultados = $conn->prepare($sql_resultados);
$params = [':prova_id' => $prova_id];
if (!empty($busca_aluno)) {
    $params[':busca'] = "%$busca_aluno%";
}
$stmt_resultados->execute($params);
$resultados = $stmt_resultados->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_alunos = count($resultados);
$total_aprovados = 0;
$total_reprovados = 0;
$total_abandonadas = 0;
$soma_notas = 0;

foreach ($resultados as $resultado) {
    if ($resultado['status'] == 'abandonada') {
        $total_abandonadas++;
    } else {
        if ($resultado['aprovado'] == 1) {
            $total_aprovados++;
        } else {
            $total_reprovados++;
        }
        $soma_notas += $resultado['pontuacao_total'];
    }
}

$media_notas = ($total_aprovados + $total_reprovados) > 0 ? round($soma_notas / ($total_aprovados + $total_reprovados), 1) : 0;
$taxa_aprovacao = ($total_aprovados + $total_reprovados) > 0 ? round(($total_aprovados / ($total_aprovados + $total_reprovados)) * 100, 1) : 0;

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    $escola = [
        'nome' => 'SIGE Angola',
        'endereco' => '',
        'telefone' => '',
        'email' => '',
        'nif' => ''
    ];
}

// Se for formato Excel (CSV)
if ($format == 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="resultados_prova_' . $prova_id . '_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    // Cabeçalho
    fputcsv($output, ['RELATÓRIO DE RESULTADOS - PROVA: ' . mb_strtoupper($prova['titulo'])]);
    fputcsv($output, ['Data de Geração:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['Disciplina:', $prova['disciplina_nome']]);
    fputcsv($output, ['Turma:', $prova['turma_ano'] . 'ª - ' . $prova['turma_nome']]);
    fputcsv($output, ['Nota Máxima:', $prova['nota_maxima']]);
    fputcsv($output, ['Nota Mínima Aprovação:', $prova['nota_minima_aprovacao']]);
    fputcsv($output, ['Duração:', $prova['duracao_minutos'] . ' minutos']);
    fputcsv($output, []);
    fputcsv($output, ['ESTATÍSTICAS']);
    fputcsv($output, ['Total de Alunos:', $total_alunos]);
    fputcsv($output, ['Aprovados:', $total_aprovados]);
    fputcsv($output, ['Reprovados:', $total_reprovados]);
    fputcsv($output, ['Abandonaram:', $total_abandonadas]);
    fputcsv($output, ['Média das Notas:', number_format($media_notas, 1)]);
    fputcsv($output, ['Taxa de Aprovação:', $taxa_aprovacao . '%']);
    fputcsv($output, []);
    fputcsv($output, ['DETALHAMENTO DOS ALUNOS']);
    fputcsv($output, ['Nº', 'Aluno', 'Matrícula', 'Tentativa', 'Data Entrega', 'Tempo (min)', 'Nota', '%', 'Status']);
    
    foreach ($resultados as $index => $resultado) {
        $tempo_min = round($resultado['tempo_gasto_segundos'] / 60, 1);
        fputcsv($output, [
            $index + 1,
            $resultado['aluno_nome'],
            $resultado['aluno_matricula'],
            $resultado['tentativa_numero'] . 'ª',
            date('d/m/Y H:i', strtotime($resultado['data_entrega'] ?? $resultado['data_fim'])),
            $tempo_min,
            $resultado['pontuacao_total'] . ' / ' . $prova['nota_maxima'],
            $resultado['porcentagem'] . '%',
            $resultado['aprovado'] == 1 ? 'Aprovado' : ($resultado['status'] == 'abandonada' ? 'Abandonou' : 'Reprovado')
        ]);
    }
    
    fclose($output);
    exit;
}

// Se for formato PDF (visualização para impressão)
if ($format == 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    // Continua para exibir o HTML otimizado para impressão
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados da Prova - <?php echo htmlspecialchars($prova['titulo']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #e0e0e0;
            font-family: 'Courier New', 'Lucida Console', monospace;
            padding: 20px;
        }
        
        .relatorio-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .relatorio {
            background: white;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .relatorio {
                box-shadow: none;
                padding: 15px;
                margin: 0;
            }
            .btn-print, .btn-voltar, .btn-excel, .no-print {
                display: none !important;
            }
            .relatorio {
                width: 100%;
            }
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .empresa-nome {
            font-size: 18pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .empresa-dados {
            font-size: 9pt;
            margin-top: 5px;
        }
        
        .titulo-relatorio {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin: 15px 0;
            text-transform: uppercase;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 10pt;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 150px;
        }
        
        .info-value {
            flex: 1;
        }
        
        .stats-box {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 20px 0;
            border: 1px solid #ccc;
            padding: 15px;
            background: #f9f9f9;
        }
        
        .stat-item {
            flex: 1;
            text-align: center;
            min-width: 100px;
        }
        
        .stat-valor {
            font-size: 18pt;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 9pt;
            margin-top: 5px;
        }
        
        .tabela-resultados {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 9pt;
        }
        
        .tabela-resultados th,
        .tabela-resultados td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .tabela-resultados th {
            background: #f0f0f0;
            font-weight: bold;
        }
        
        .tabela-resultados td.text-center {
            text-align: center;
        }
        
        .tabela-resultados td.text-right {
            text-align: right;
        }
        
        .aprovado {
            color: #28a745;
            font-weight: bold;
        }
        
        .reprovado {
            color: #dc3545;
            font-weight: bold;
        }
        
        .abandonou {
            color: #6c757d;
        }
        
        .rodape {
            text-align: center;
            font-size: 8pt;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
        }
        
        .assinatura {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        
        .assinatura-item {
            text-align: center;
            width: 45%;
        }
        
        .linha-assinatura {
            border-top: 1px solid #000;
            width: 100%;
            margin-top: 30px;
            padding-top: 5px;
        }
        
        .btn-print, .btn-voltar, .btn-excel {
            position: fixed;
            bottom: 20px;
            padding: 12px 24px;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-family: Arial, sans-serif;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
            text-decoration: none;
        }
        
        .btn-print {
            right: 20px;
            background: #006B3E;
            color: white;
        }
        
        .btn-print:hover {
            background: #004d2d;
        }
        
        .btn-excel {
            right: 160px;
            background: #28a745;
            color: white;
        }
        
        .btn-excel:hover {
            background: #218838;
        }
        
        .btn-voltar {
            left: 20px;
            background: #6c757d;
            color: white;
        }
        
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        
        .codigo-barras {
            font-family: 'Courier New', monospace;
            font-size: 20pt;
            letter-spacing: 2px;
            text-align: center;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="relatorio-container">
        <div class="relatorio" id="relatorio">
            <!-- Cabeçalho -->
            <div class="header">
                <div class="empresa-nome"><?php echo mb_strtoupper(htmlspecialchars($escola['nome'])); ?></div>
                <div class="empresa-dados">
                    <?php if (!empty($escola['endereco'])): ?>
                    <?php echo htmlspecialchars($escola['endereco']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($escola['telefone'])): ?>
                    Tel: <?php echo htmlspecialchars($escola['telefone']); ?> | 
                    <?php endif; ?>
                    <?php if (!empty($escola['email'])): ?>
                    Email: <?php echo htmlspecialchars($escola['email']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($escola['nif'])): ?>
                    NIF: <?php echo htmlspecialchars($escola['nif']); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Código de Barras Simbólico -->
            <div class="codigo-barras">
                <?php echo str_repeat('█', 45); ?><br>
                RESULTADOS - PROVA #<?php echo $prova_id; ?><br>
                <?php echo str_repeat('█', 45); ?>
            </div>
            
            <!-- Título -->
            <div class="titulo-relatorio">
                <i class="fas fa-chart-line"></i> RELATÓRIO DE RESULTADOS
            </div>
            
            <!-- Informações da Prova -->
            <div class="info-row">
                <span class="info-label">Prova:</span>
                <span class="info-value"><?php echo mb_strtoupper(htmlspecialchars($prova['titulo'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Disciplina:</span>
                <span class="info-value"><?php echo htmlspecialchars($prova['disciplina_nome']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Turma:</span>
                <span class="info-value"><?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Data de Geração:</span>
                <span class="info-value"><?php echo date('d/m/Y H:i:s'); ?></span>
            </div>
            
            <!-- Estatísticas -->
            <div class="stats-box">
                <div class="stat-item">
                    <div class="stat-valor"><?php echo $total_alunos; ?></div>
                    <div class="stat-label">Total de Alunos</div>
                </div>
                <div class="stat-item">
                    <div class="stat-valor text-success"><?php echo $total_aprovados; ?></div>
                    <div class="stat-label">Aprovados</div>
                </div>
                <div class="stat-item">
                    <div class="stat-valor text-danger"><?php echo $total_reprovados; ?></div>
                    <div class="stat-label">Reprovados</div>
                </div>
                <div class="stat-item">
                    <div class="stat-valor text-secondary"><?php echo $total_abandonadas; ?></div>
                    <div class="stat-label">Abandonaram</div>
                </div>
                <div class="stat-item">
                    <div class="stat-valor text-info"><?php echo number_format($media_notas, 1); ?></div>
                    <div class="stat-label">Média das Notas</div>
                </div>
                <div class="stat-item">
                    <div class="stat-valor text-warning"><?php echo $taxa_aprovacao; ?>%</div>
                    <div class="stat-label">Taxa de Aprovação</div>
                </div>
            </div>
            
            <!-- Parâmetros da Prova -->
            <div class="info-row">
                <span class="info-label">Nota Máxima:</span>
                <span class="info-value"><?php echo $prova['nota_maxima']; ?> pontos</span>
            </div>
            <div class="info-row">
                <span class="info-label">Nota Mínima Aprovação:</span>
                <span class="info-value"><?php echo $prova['nota_minima_aprovacao']; ?> pontos</span>
            </div>
            <div class="info-row">
                <span class="info-label">Duração:</span>
                <span class="info-value"><?php echo $prova['duracao_minutos']; ?> minutos</span>
            </div>
            
            <!-- Tabela de Resultados -->
            <table class="tabela-resultados">
                <thead>
                    <tr>
                        <th width="40">#</th>
                        <th>Aluno</th>
                        <th width="100">Matrícula</th>
                        <th width="60">Tent.</th>
                        <th width="100">Data Entrega</th>
                        <th width="80">Tempo</th>
                        <th width="80">Nota</th>
                        <th width="60">%</th>
                        <th width="100">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $index => $resultado): 
                        $tempo_min = floor($resultado['tempo_gasto_segundos'] / 60);
                        $tempo_seg = $resultado['tempo_gasto_segundos'] % 60;
                        $tempo_formatado = sprintf("%02d:%02d", $tempo_min, $tempo_seg);
                        $status_class = '';
                        $status_texto = '';
                        
                        if ($resultado['status'] == 'abandonada') {
                            $status_class = 'abandonou';
                            $status_texto = 'Abandonou';
                        } elseif ($resultado['aprovado'] == 1) {
                            $status_class = 'aprovado';
                            $status_texto = 'Aprovado';
                        } else {
                            $status_class = 'reprovado';
                            $status_texto = 'Reprovado';
                        }
                    ?>
                    <tr>
                        <td class="text-center"><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($resultado['aluno_nome']); ?></td>
                        <td><?php echo htmlspecialchars($resultado['aluno_matricula']); ?></td>
                        <td class="text-center"><?php echo $resultado['tentativa_numero']; ?>ª</td>
                        <td class="text-center"><?php echo date('d/m/Y H:i', strtotime($resultado['data_entrega'] ?? $resultado['data_fim'])); ?></td>
                        <td class="text-center"><?php echo $tempo_formatado; ?></td>
                        <td class="text-center">
                            <strong><?php echo number_format($resultado['pontuacao_total'], 1); ?></strong> / <?php echo $prova['nota_maxima']; ?>
                        </td>
                        <td class="text-center"><?php echo round($resultado['porcentagem'], 1); ?>%</td>
                        <td class="text-center <?php echo $status_class; ?>"><?php echo $status_texto; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="6" class="text-end">MÉDIA GERAL: </td>
                        <td class="text-center"><?php echo number_format($media_notas, 1); ?></td>
                        <td class="text-center"><?php echo $media_notas > 0 ? round(($media_notas / $prova['nota_maxima']) * 100, 1) : 0; ?>%</td>
                        <td class="text-center">-</td>
                    </tr>
                </tfoot>
            </table>
            
            <!-- Assinaturas -->
            <div class="assinatura">
                <div class="assinatura-item">
                    <div class="linha-assinatura"></div>
                    <div>Professor Responsável</div>
                </div>
                <div class="assinatura-item">
                    <div class="linha-assinatura"></div>
                    <div>Coordenador Pedagógico</div>
                </div>
            </div>
            
            <!-- Rodapé -->
            <div class="rodape">
                Documento emitido por computador - Válido como relatório de resultados<br>
                <?php echo "SIGE Angola - Sistema Integrado de Gestão Escolar " . date('Y'); ?><br>
                <?php echo "Processado em: " . date('d/m/Y H:i:s'); ?>
            </div>
        </div>
    </div>
    
    <a href="resultados_prova.php?id=<?php echo $prova_id; ?>" class="btn-voltar no-print">
        <i class="fas fa-arrow-left"></i> Voltar
    </a>
    
    <a href="?id=<?php echo $prova_id; ?>&finalizadas=<?php echo $apenas_finalizadas; ?>&busca=<?php echo urlencode($busca_aluno); ?>&format=excel" class="btn-excel no-print">
        <i class="fas fa-file-excel"></i> Exportar Excel
    </a>
    
    <button class="btn-print no-print" onclick="window.print();">
        <i class="fas fa-print"></i> Imprimir Relatório
    </button>
    
    <script>
        <?php if (isset($_GET['print']) && $_GET['print'] == 1): ?>
        setTimeout(function() {
            window.print();
        }, 500);
        <?php endif; ?>
    </script>
</body>
</html>