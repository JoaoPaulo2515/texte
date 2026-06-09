<?php
// escola/pedagogico/relatorio_faltas.php - Relatório de Faltas

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// Processar download do histórico de faltas de um aluno
if (isset($_GET['exportar_aluno']) && isset($_GET['aluno_id'])) {
    $aluno_id = (int)$_GET['aluno_id'];
    $turma_id_export = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
    $ano_letivo_export = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
    
    // Buscar dados do aluno
    $sql_aluno = "SELECT nome, matricula FROM estudantes WHERE id = :id AND escola_id = :escola_id";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([':id' => $aluno_id, ':escola_id' => $escola_id]);
    $aluno_dados = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if ($aluno_dados) {
        // Buscar faltas do aluno
        $sql_faltas_export = "
            SELECT 
                c.data_aula,
                c.status,
                c.minutos_atraso,
                c.justificativa,
                c.observacao,
                c.bimestre,
                d.nome as disciplina_nome,
                d.codigo as disciplina_codigo,
                t.nome as turma_nome,
                t.ano as turma_ano,
                al.ano as ano_letivo
            FROM chamada c
            INNER JOIN disciplinas d ON d.id = c.disciplina_id
            INNER JOIN turmas t ON t.id = c.turma_id
            LEFT JOIN ano_letivo al ON al.id = c.ano_letivo_id
            WHERE c.estudante_id = :aluno_id
            AND c.status IN ('falta', 'atrasado')
        ";
        
        $params_export = [':aluno_id' => $aluno_id];
        
        if ($turma_id_export > 0) {
            $sql_faltas_export .= " AND c.turma_id = :turma_id";
            $params_export[':turma_id'] = $turma_id_export;
        }
        if ($ano_letivo_export > 0) {
            $sql_faltas_export .= " AND c.ano_letivo_id = :ano_letivo_id";
            $params_export[':ano_letivo_id'] = $ano_letivo_export;
        }
        
        $sql_faltas_export .= " ORDER BY c.data_aula DESC";
        
        $stmt_faltas_export = $conn->prepare($sql_faltas_export);
        $stmt_faltas_export->execute($params_export);
        $faltas_export = $stmt_faltas_export->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular estatísticas
        $total_faltas_export = count(array_filter($faltas_export, function($f) { return $f['status'] == 'falta'; }));
        $total_atrasos_export = count(array_filter($faltas_export, function($f) { return $f['status'] == 'atrasado'; }));
        
        // Gerar CSV
        $filename = 'historico_faltas_' . preg_replace('/[^a-zA-Z0-9]/', '_', $aluno_dados['nome']) . '_' . date('Ymd_His') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Criar o arquivo CSV
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Cabeçalho do relatório
        fputcsv($output, ['RELATÓRIO DE FALTAS E ATRASOS']);
        fputcsv($output, ['']);
        fputcsv($output, ['DADOS DO ALUNO']);
        fputcsv($output, ['Nome:', $aluno_dados['nome']]);
        fputcsv($output, ['Matrícula:', $aluno_dados['matricula']]);
        fputcsv($output, ['Data de Emissão:', date('d/m/Y H:i:s')]);
        fputcsv($output, ['']);
        fputcsv($output, ['ESTATÍSTICAS']);
        fputcsv($output, ['Total de Faltas:', $total_faltas_export]);
        fputcsv($output, ['Total de Atrasos:', $total_atrasos_export]);
        fputcsv($output, ['Total de Registros:', count($faltas_export)]);
        fputcsv($output, ['']);
        fputcsv($output, ['DETALHAMENTO DAS FALTAS E ATRASOS']);
        fputcsv($output, ['Data', 'Disciplina', 'Turma', 'Status', 'Minutos Atraso', 'Justificativa', 'Observação', 'Bimestre']);
        
        foreach ($faltas_export as $falta) {
            $status_texto = $falta['status'] == 'falta' ? 'Falta' : 'Atrasado';
            fputcsv($output, [
                date('d/m/Y', strtotime($falta['data_aula'])),
                $falta['disciplina_nome'] . ' (' . $falta['disciplina_codigo'] . ')',
                $falta['turma_nome'] . ' - ' . $falta['turma_ano'] . 'ª',
                $status_texto,
                $falta['status'] == 'atrasado' ? $falta['minutos_atraso'] . ' min' : '-',
                $falta['justificativa'] ?? '',
                $falta['observacao'] ?? '',
                $falta['bimestre'] . 'º Bimestre'
            ]);
        }
        
        fclose($output);
        exit;
    }
}

// Buscar turmas para filtro
$sql_turmas = "
    SELECT t.id, t.nome, t.ano, tr.nome as turno_nome
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas para filtro
$sql_disciplinas = "
    SELECT d.id, d.nome, d.codigo
    FROM disciplinas d
    WHERE d.escola_id = :escola_id AND d.status = 'ativo'
    ORDER BY d.nome ASC
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar anos letivos
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Parâmetros de filtro
$turma_filtro = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_filtro = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-d', strtotime('-30 days'));
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');
$aluno_filtro = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;

// Se não tem ano letivo selecionado, pegar o mais recente
if ($ano_letivo_filtro == 0 && !empty($anos_letivos)) {
    $ano_letivo_filtro = $anos_letivos[0]['id'];
}

// Buscar alunos da turma selecionada
$alunos = [];
if ($turma_filtro > 0) {
    $sql_alunos = "
        SELECT e.id, e.nome, e.matricula
        FROM estudantes e
        INNER JOIN matriculas m ON m.estudante_id = e.id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome ASC
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_filtro]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// ==============================================
// RELATÓRIO DE FALTAS
// ==============================================

// SQL base para buscar faltas
$sql_faltas = "
    SELECT 
        c.id,
        c.estudante_id,
        c.disciplina_id,
        c.data_aula,
        c.status,
        c.minutos_atraso,
        c.justificativa,
        c.observacao,
        c.bimestre,
        e.nome as estudante_nome,
        e.matricula,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        t.nome as turma_nome,
        t.ano as turma_ano,
        al.ano as ano_letivo
    FROM chamada c
    INNER JOIN estudantes e ON e.id = c.estudante_id
    INNER JOIN disciplinas d ON d.id = c.disciplina_id
    INNER JOIN turmas t ON t.id = c.turma_id
    LEFT JOIN ano_letivo al ON al.id = c.ano_letivo_id
    WHERE c.escola_id = :escola_id
    AND c.status IN ('falta', 'atrasado')
";

$params = [':escola_id' => $escola_id];

if ($turma_filtro > 0) {
    $sql_faltas .= " AND c.turma_id = :turma_id";
    $params[':turma_id'] = $turma_filtro;
}
if ($disciplina_filtro > 0) {
    $sql_faltas .= " AND c.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_filtro;
}
if ($ano_letivo_filtro > 0) {
    $sql_faltas .= " AND c.ano_letivo_id = :ano_letivo_id";
    $params[':ano_letivo_id'] = $ano_letivo_filtro;
}
if ($bimestre_filtro > 0) {
    $sql_faltas .= " AND c.bimestre = :bimestre";
    $params[':bimestre'] = $bimestre_filtro;
}
if ($data_inicio) {
    $sql_faltas .= " AND c.data_aula >= :data_inicio";
    $params[':data_inicio'] = $data_inicio;
}
if ($data_fim) {
    $sql_faltas .= " AND c.data_aula <= :data_fim";
    $params[':data_fim'] = $data_fim;
}
if ($aluno_filtro > 0) {
    $sql_faltas .= " AND c.estudante_id = :aluno_id";
    $params[':aluno_id'] = $aluno_filtro;
}

$sql_faltas .= " ORDER BY c.data_aula DESC, e.nome ASC";

$stmt_faltas = $conn->prepare($sql_faltas);
$stmt_faltas->execute($params);
$faltas = $stmt_faltas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// RESUMO POR ALUNO
// ==============================================
$resumo_alunos = [];
foreach ($faltas as $falta) {
    $aluno_id = $falta['estudante_id'];
    if (!isset($resumo_alunos[$aluno_id])) {
        $resumo_alunos[$aluno_id] = [
            'id' => $aluno_id,
            'nome' => $falta['estudante_nome'],
            'matricula' => $falta['matricula'],
            'total_faltas' => 0,
            'total_atrasos' => 0,
            'disciplinas' => []
        ];
    }
    
    if ($falta['status'] == 'falta') {
        $resumo_alunos[$aluno_id]['total_faltas']++;
    } elseif ($falta['status'] == 'atrasado') {
        $resumo_alunos[$aluno_id]['total_atrasos']++;
    }
    
    $disciplina = $falta['disciplina_nome'];
    if (!isset($resumo_alunos[$aluno_id]['disciplinas'][$disciplina])) {
        $resumo_alunos[$aluno_id]['disciplinas'][$disciplina] = [
            'faltas' => 0,
            'atrasos' => 0
        ];
    }
    
    if ($falta['status'] == 'falta') {
        $resumo_alunos[$aluno_id]['disciplinas'][$disciplina]['faltas']++;
    } elseif ($falta['status'] == 'atrasado') {
        $resumo_alunos[$aluno_id]['disciplinas'][$disciplina]['atrasos']++;
    }
}

// Estatísticas gerais
$total_faltas = count(array_filter($faltas, function($f) { return $f['status'] == 'falta'; }));
$total_atrasos = count(array_filter($faltas, function($f) { return $f['status'] == 'atrasado'; }));
$total_registros = count($faltas);
$total_alunos_com_falta = count($resumo_alunos);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Faltas - SIGE Angola</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-title h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header-title p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .btn-voltar:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-3px);
        }
        
        .btn-imprimir {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            margin-left: 10px;
        }
        
        .btn-imprimir:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .btn-excel {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            margin-left: 10px;
        }
        
        .btn-excel:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .filtros-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .filtros-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .filtros-body {
            padding: 20px;
        }
        
        .filtros-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filtro-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filtro-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .filtro-select, .filtro-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            background: white;
        }
        
        .btn-filtrar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-filtrar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-limpar {
            background: #95a5a6;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-limpar:hover {
            background: #7f8c8d;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card.blue { border-bottom: 4px solid #1e5799; }
        .stat-card.red { border-bottom: 4px solid #e74c3c; }
        .stat-card.orange { border-bottom: 4px solid #f39c12; }
        .stat-card.green { border-bottom: 4px solid #27ae60; }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-body {
            padding: 0;
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 13px;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
            font-size: 13px;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-falta {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .badge-atrasado {
            background: #fef9e7;
            color: #f39c12;
        }
        
        .resumo-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .resumo-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 12px 20px;
            font-weight: bold;
            color: #2c3e50;
            border-bottom: 1px solid #dee2e6;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .resumo-header:hover {
            background: #e9ecef;
        }
        
        .resumo-body {
            padding: 15px;
            display: none;
        }
        
        .resumo-body.active {
            display: block;
        }
        
        .resumo-aluno {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .resumo-aluno-info {
            flex: 1;
        }
        
        .resumo-aluno-nome {
            font-weight: bold;
            color: #1e5799;
        }
        
        .resumo-aluno-matricula {
            font-size: 11px;
            color: #7f8c8d;
        }
        
        .resumo-aluno-stats {
            display: flex;
            gap: 20px;
        }
        
        .resumo-stat {
            text-align: center;
        }
        
        .resumo-stat-number {
            font-size: 20px;
            font-weight: bold;
        }
        
        .resumo-stat-label {
            font-size: 10px;
            color: #7f8c8d;
        }
        
        .disciplinas-list {
            margin-top: 10px;
            padding-left: 20px;
        }
        
        .disciplina-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px dashed #ecf0f1;
        }
        
        .disciplina-nome {
            font-size: 12px;
        }
        
        .disciplina-faltas {
            font-size: 12px;
            font-weight: bold;
            color: #e74c3c;
        }
        
        .btn-download {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .alert-info {
            background: #d4e6f1;
            color: #1e5799;
            border-left: 4px solid #1e5799;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .filtros-row {
                flex-direction: column;
            }
            
            .filtro-group {
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table {
                font-size: 11px;
            }
            
            .table th, .table td {
                padding: 8px;
            }
            
            .resumo-aluno {
                flex-direction: column;
                text-align: center;
            }
            
            .resumo-aluno-stats {
                justify-content: center;
            }
        }
        
        @media print {
            .btn-voltar, .btn-imprimir, .btn-excel, .filtros-card, .btn-filtrar, .btn-limpar, .btn-download {
                display: none;
            }
            
            body {
                background: white;
                padding: 0;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .resumo-body {
                display: block !important;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📊 Relatório de Faltas</h1>
            <p>Visualize e analise as faltas e atrasos dos alunos</p>
        </div>
        <div>
            <button onclick="window.print()" class="btn-imprimir">
                🖨️ Imprimir Relatório
            </button>
            <a href="relatorio_faltas.php?exportar_todos=1<?php echo $turma_filtro > 0 ? '&turma_id=' . $turma_filtro : ''; ?><?php echo $ano_letivo_filtro > 0 ? '&ano_letivo_id=' . $ano_letivo_filtro : ''; ?>" class="btn-excel">
                📊 Exportar Todos
            </a>
            <a href="index.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filtros-card">
        <div class="filtros-header">
            🔍 Filtrar Relatório
        </div>
        <div class="filtros-body">
            <form method="GET" action="" class="filtros-row">
                <div class="filtro-group">
                    <label>Turma</label>
                    <select name="turma_id" class="filtro-select" onchange="this.form.submit()">
                        <option value="0">Todas as turmas</option>
                        <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo ($turma_filtro == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['nome']); ?> - <?php echo $t['ano']; ?>ª - <?php echo ucfirst($t['turno_nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Disciplina</label>
                    <select name="disciplina_id" class="filtro-select">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo ($disciplina_filtro == $d['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['nome']); ?> (<?php echo htmlspecialchars($d['codigo']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Ano Letivo</label>
                    <select name="ano_letivo_id" class="filtro-select">
                        <option value="0">Todos os anos</option>
                        <?php foreach ($anos_letivos as $ano): ?>
                            <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_filtro == $ano['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ano['ano']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Bimestre</label>
                    <select name="bimestre" class="filtro-select">
                        <option value="0">Todos</option>
                        <option value="1" <?php echo ($bimestre_filtro == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo ($bimestre_filtro == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo ($bimestre_filtro == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                        <option value="4" <?php echo ($bimestre_filtro == 4) ? 'selected' : ''; ?>>4º Bimestre</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Data Início</label>
                    <input type="date" name="data_inicio" class="filtro-input" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="filtro-group">
                    <label>Data Fim</label>
                    <input type="date" name="data_fim" class="filtro-input" value="<?php echo $data_fim; ?>">
                </div>
                <?php if ($turma_filtro > 0): ?>
                <div class="filtro-group">
                    <label>Aluno</label>
                    <select name="aluno_id" class="filtro-select">
                        <option value="0">Todos os alunos</option>
                        <?php foreach ($alunos as $a): ?>
                            <option value="<?php echo $a['id']; ?>" <?php echo ($aluno_filtro == $a['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['nome']); ?> (<?php echo htmlspecialchars($a['matricula']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filtro-group">
                    <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
                    <a href="relatorio_faltas.php" class="btn-limpar">🗑️ Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-number"><?php echo $total_registros; ?></div>
            <div class="stat-label">Total de Registros</div>
        </div>
        <div class="stat-card red">
            <div class="stat-number"><?php echo $total_faltas; ?></div>
            <div class="stat-label">❌ Faltas</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-number"><?php echo $total_atrasos; ?></div>
            <div class="stat-label">⏰ Atrasos</div>
        </div>
        <div class="stat-card green">
            <div class="stat-number"><?php echo $total_alunos_com_falta; ?></div>
            <div class="stat-label">Alunos com Falta</div>
        </div>
    </div>
    
    <!-- Resumo por Aluno -->
    <?php if (!empty($resumo_alunos)): ?>
    <div class="resumo-card">
        <div class="card-header">
            📋 Resumo por Aluno
        </div>
        <div class="card-body" style="padding: 0;">
            <?php foreach ($resumo_alunos as $aluno_id => $resumo): ?>
                <div class="resumo-header" onclick="toggleResumo(this)">
                    <span>
                        <strong><?php echo htmlspecialchars($resumo['nome']); ?></strong>
                        <small style="color: #7f8c8d;"> - <?php echo htmlspecialchars($resumo['matricula']); ?></small>
                    </span>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span>
                            <span style="color: #e74c3c;">❌ <?php echo $resumo['total_faltas']; ?> faltas</span>
                            <span style="color: #f39c12; margin-left: 15px;">⏰ <?php echo $resumo['total_atrasos']; ?> atrasos</span>
                        </span>
                       <a href="gerar_pdf_historico_faltas.php?aluno_id=<?php echo $falta['estudante_id']; ?>&turma_id=<?php echo $turma_filtro; ?>&ano_letivo_id=<?php echo $ano_letivo_filtro; ?>" class="btn-download" title="Baixar histórico deste aluno">
                                       📥 Baixar Histórico
                        </a>
                        <span style="margin-left: 10px;">▼</span>
                    </div>
                </div>
                <div class="resumo-body">
                    <div class="disciplinas-list">
                        <?php foreach ($resumo['disciplinas'] as $disciplina => $stats): ?>
                            <div class="disciplina-item">
                                <span class="disciplina-nome">📖 <?php echo htmlspecialchars($disciplina); ?></span>
                                <div>
                                    <span class="disciplina-faltas">❌ <?php echo $stats['faltas']; ?> faltas</span>
                                    <?php if ($stats['atrasos'] > 0): ?>
                                        <span style="margin-left: 15px; color: #f39c12;">⏰ <?php echo $stats['atrasos']; ?> atrasos</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Lista Detalhada de Faltas -->
    <div class="card">
        <div class="card-header">
            <span>📋 Lista Detalhada de Faltas e Atrasos</span>
            <span class="badge-count"><?php echo $total_registros; ?> registros</span>
        </div>
        <div class="card-body">
            <?php if (empty($faltas)): ?>
                <div style="text-align: center; padding: 50px;">
                    <p style="color: #7f8c8d;">Nenhuma falta ou atraso registrado.</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Aluno</th>
                            <th>Matrícula</th>
                            <th>Disciplina</th>
                            <th>Turma</th>
                            <th>Status</th>
                            <th>Justificativa</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faltas as $falta): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($falta['data_aula'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($falta['estudante_nome']); ?></strong>
                                 </div>
                                <td><?php echo htmlspecialchars($falta['matricula']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($falta['disciplina_nome']); ?><br>
                                    <small><?php echo htmlspecialchars($falta['disciplina_codigo']); ?></small>
                                 </div>
                                <td>
                                    <?php echo htmlspecialchars($falta['turma_nome']); ?> - <?php echo $falta['turma_ano']; ?>ª
                                 </div>
                                <td>
                                    <span class="badge badge-<?php echo $falta['status']; ?>">
                                        <?php echo $falta['status'] == 'falta' ? '❌ Falta' : '⏰ Atrasado'; ?>
                                        <?php if ($falta['status'] == 'atrasado' && $falta['minutos_atraso'] > 0): ?>
                                            (<?php echo $falta['minutos_atraso']; ?> min)
                                        <?php endif; ?>
                                    </span>
                                 </div>
                                <td>
                                    <?php echo htmlspecialchars(substr($falta['justificativa'] ?? '', 0, 50)) . (strlen($falta['justificativa'] ?? '') > 50 ? '...' : ''); ?>
                                 </div>
                                <td>
                                    <a href="gerar_pdf_historico_faltas.php?aluno_id=<?php echo $falta['estudante_id']; ?>&turma_id=<?php echo $turma_filtro; ?>&ano_letivo_id=<?php echo $ano_letivo_filtro; ?>" class="btn-download" title="Baixar histórico deste aluno">
                                       📥 Baixar Histórico PDF
                                    </a> 
                                 </div>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Observações -->
    <div class="card">
        <div class="card-header">
            ℹ️ Observações
        </div>
        <div class="card-body" style="padding: 20px;">
            <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                <li>Este relatório considera apenas faltas e atrasos registrados no sistema.</li>
                <li>Faltas justificadas são contabilizadas normalmente, mas podem ser analisadas separadamente.</li>
                <li>Atrasos são contabilizados separadamente, com registro dos minutos.</li>
                <li>O relatório pode ser filtrado por período, turma, disciplina e aluno.</li>
                <li>Clique no botão <strong>📥 Baixar Histórico</strong> ao lado de cada aluno para exportar o histórico completo em CSV.</li>
                <li>Utilize o botão "Imprimir" para gerar uma versão para papel.</li>
            </ul>
        </div>
    </div>
</div>

<script>
    function toggleResumo(element) {
        const body = element.nextElementSibling;
        body.classList.toggle('active');
        const arrow = element.querySelector('span:last-child');
        if (body.classList.contains('active')) {
            arrow.innerHTML = '▲';
        } else {
            arrow.innerHTML = '▼';
        }
    }
    
    // Abrir primeiro resumo por padrão
    document.addEventListener('DOMContentLoaded', function() {
        const firstResumo = document.querySelector('.resumo-body');
        if (firstResumo) {
            firstResumo.classList.add('active');
            const firstHeader = document.querySelector('.resumo-header');
            if (firstHeader) {
                const arrow = firstHeader.querySelector('span:last-child');
                if (arrow) arrow.innerHTML = '▲';
            }
        }
    });
</script>
</body>
</html>