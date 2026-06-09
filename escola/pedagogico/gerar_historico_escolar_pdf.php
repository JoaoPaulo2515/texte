<?php
// escola/pedagogico/gerar_historico_escolar_pdf.php - Gerar Histórico Escolar PDF

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

// Parâmetros
$estudante_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['estudante_id']) ? (int)$_GET['estudante_id'] : 0);

if ($estudante_id <= 0) {
    die('ID do estudante não especificado. Use: gerar_historico_escolar_pdf.php?id=10');
}

// Buscar dados do estudante
$sql_aluno = "
    SELECT 
        e.id as estudante_id,
        e.nome as estudante_nome,
        e.matricula as estudante_matricula,
        e.bi,
        DATE_FORMAT(e.data_nascimento, '%d/%m/%Y') as data_nascimento,
        e.genero,
        e.endereco,
        e.telefone,
        e.email,
        e.pai_nome,
        e.mae_nome,
        e.encarregado_nome,
        e.encarregado_telefone,
        e.encarregado_email,
        esc.id as escola_id,
        esc.nome as escola_nome,
        esc.endereco as escola_endereco,
        esc.telefone as escola_telefone,
        esc.email as escola_email,
        esc.nif as escola_nif
    FROM estudantes e
    INNER JOIN escolas esc ON esc.id = e.escola_id
    WHERE e.id = :estudante_id
";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':estudante_id' => $estudante_id]);
$dados_aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$dados_aluno) {
    die('Estudante não encontrado');
}

// Buscar matrícula atual
$sql_matricula_atual = "
    SELECT 
        m.id as matricula_id,
        DATE_FORMAT(m.data_matricula, '%d/%m/%Y') as data_matricula,
        m.ano_letivo,
        m.status as matricula_status,
        t.id as turma_id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala
    FROM matriculas m
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE m.estudante_id = :estudante_id AND m.status = 'ativa'
    ORDER BY m.data_matricula DESC
    LIMIT 1
";
$stmt_matricula_atual = $conn->prepare($sql_matricula_atual);
$stmt_matricula_atual->execute([':estudante_id' => $estudante_id]);
$matricula_atual = $stmt_matricula_atual->fetch(PDO::FETCH_ASSOC);

// Buscar histórico de matrículas
$sql_historico = "
    SELECT 
        m.id as matricula_id,
        DATE_FORMAT(m.data_matricula, '%d/%m/%Y') as data_matricula,
        DATE_FORMAT(m.data_cancelamento, '%d/%m/%Y') as data_cancelamento,
        m.ano_letivo,
        m.status as matricula_status,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala
    FROM matriculas m
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE m.estudante_id = :estudante_id
    ORDER BY m.ano_letivo DESC, m.data_matricula DESC
";
$stmt_historico = $conn->prepare($sql_historico);
$stmt_historico->execute([':estudante_id' => $estudante_id]);
$historico_matriculas = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR NOTAS DA TABELA 'notas'
// ============================================
$sql_notas = "
    SELECT 
        n.id,
        n.mac,
        n.npt,
        n.exame_normal,
        n.exame_recurso,
        n.exame_especial,
        n.media_parcial,
        n.media_final,
        n.status as nota_status,
        n.bimestre,
        n.observacao_academica,
        n.data_lancamento,
        n.ano_letivo_id,
        d.id as disciplina_id,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        al.ano as ano_letivo
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    INNER JOIN anos_letivos al ON al.id = n.ano_letivo_id
    WHERE n.estudante_id = :estudante_id
    ORDER BY al.ano DESC, n.bimestre ASC, d.nome ASC
";
$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':estudante_id' => $estudante_id]);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR FREQUÊNCIA DA TABELA 'chamada'
// ============================================
$sql_frequencia = "
    SELECT 
        c.id,
        c.data_aula,
        c.status,
        c.minutos_atraso,
        c.justificativa,
        c.bimestre,
        c.ano_letivo_id,
        d.id as disciplina_id,
        d.nome as disciplina_nome,
        al.ano as ano_letivo
    FROM chamada c
    INNER JOIN disciplinas d ON d.id = c.disciplina_id
    INNER JOIN anos_letivos al ON al.id = c.ano_letivo_id
    WHERE c.estudante_id = :estudante_id
    ORDER BY al.ano DESC, c.bimestre ASC, d.nome ASC
";
$stmt_frequencia = $conn->prepare($sql_frequencia);
$stmt_frequencia->execute([':estudante_id' => $estudante_id]);
$frequencias = $stmt_frequencia->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_matriculas = count($historico_matriculas);
$matriculas_ativas = 0;
$matriculas_concluidas = 0;
$matriculas_canceladas = 0;

foreach ($historico_matriculas as $mat) {
    if ($mat['matricula_status'] == 'ativa') $matriculas_ativas++;
    elseif ($mat['matricula_status'] == 'concluida') $matriculas_concluidas++;
    elseif ($mat['matricula_status'] == 'cancelada') $matriculas_canceladas++;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function classificarNota($nota) {
    if ($nota >= 18) return ['texto' => 'Excelente', 'classe' => 'nota-excelente', 'icon' => '🌟'];
    if ($nota >= 14) return ['texto' => 'Muito Bom', 'classe' => 'nota-muito-bom', 'icon' => '⭐'];
    if ($nota >= 10) return ['texto' => 'Bom', 'classe' => 'nota-bom', 'icon' => '✅'];
    if ($nota >= 7) return ['texto' => 'Satisfatório', 'classe' => 'nota-satisfatorio', 'icon' => '⚠️'];
    return ['texto' => 'Insuficiente', 'classe' => 'nota-insuficiente', 'icon' => '❌'];
}

function classificarStatusAluno($media_final) {
    if ($media_final >= 10) return ['texto' => 'Aprovado', 'classe' => 'status-aprovado', 'icon' => '✅'];
    if ($media_final >= 7) return ['texto' => 'Recuperação', 'classe' => 'status-recuperacao', 'icon' => '⚠️'];
    return ['texto' => 'Reprovado', 'classe' => 'status-reprovado', 'icon' => '❌'];
}

function classificarFrequencia($percentual) {
    if ($percentual >= 75) return ['texto' => 'Boa Frequência', 'classe' => 'frequencia-boa', 'icon' => '✅'];
    if ($percentual >= 50) return ['texto' => 'Frequência Regular', 'classe' => 'frequencia-regular', 'icon' => '⚠️'];
    return ['texto' => 'Baixa Frequência', 'classe' => 'frequencia-baixa', 'icon' => '❌'];
}

// Agrupar notas por ano letivo e disciplina
$notas_por_ano_disciplina = [];
foreach ($notas as $nota) {
    $ano = $nota['ano_letivo'];
    $disciplina_id = $nota['disciplina_id'];
    
    if (!isset($notas_por_ano_disciplina[$ano])) {
        $notas_por_ano_disciplina[$ano] = [];
    }
    if (!isset($notas_por_ano_disciplina[$ano][$disciplina_id])) {
        $notas_por_ano_disciplina[$ano][$disciplina_id] = [
            'disciplina_nome' => $nota['disciplina_nome'],
            'disciplina_codigo' => $nota['disciplina_codigo'],
            'bimestres' => [],
            'media_final' => 0,
            'nota_status' => ''
        ];
    }
    
    $bimestre = $nota['bimestre'];
    $notas_por_ano_disciplina[$ano][$disciplina_id]['bimestres'][$bimestre] = $nota['mac'];
    $notas_por_ano_disciplina[$ano][$disciplina_id]['media_final'] = $nota['media_final'];
    $notas_por_ano_disciplina[$ano][$disciplina_id]['nota_status'] = $nota['nota_status'];
}

// Agrupar frequências por ano/disciplina
$frequencia_por_ano_disciplina = [];
foreach ($frequencias as $freq) {
    $ano = $freq['ano_letivo'];
    $disciplina_id = $freq['disciplina_id'];
    $bimestre = $freq['bimestre'];
    
    if (!isset($frequencia_por_ano_disciplina[$ano])) {
        $frequencia_por_ano_disciplina[$ano] = [];
    }
    if (!isset($frequencia_por_ano_disciplina[$ano][$disciplina_id])) {
        $frequencia_por_ano_disciplina[$ano][$disciplina_id] = [
            'disciplina_nome' => $freq['disciplina_nome'],
            'total_presencas' => 0,
            'total_faltas' => 0,
            'total_aulas' => 0
        ];
    }
    
    if ($freq['status'] == 'presente') {
        $frequencia_por_ano_disciplina[$ano][$disciplina_id]['total_presencas']++;
    } else {
        $frequencia_por_ano_disciplina[$ano][$disciplina_id]['total_faltas']++;
    }
    $frequencia_por_ano_disciplina[$ano][$disciplina_id]['total_aulas']++;
}

// Gerar código de autenticação
$codigo_autenticacao = strtoupper(substr(md5($estudante_id . date('Ymd')), 0, 16));

// Carregar Dompdf
require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Histórico Escolar - ' . htmlspecialchars($dados_aluno['estudante_nome']) . '</title>
    <style>
        @page { 
            margin: 1.5cm;
            size: A4;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: "DejaVu Sans", "Arial", sans-serif; 
            font-size: 9pt; 
            color: #2c3e50; 
            line-height: 1.3;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 3px solid #1e5799;
        }
        
        .escola-nome { 
            font-size: 16pt; 
            font-weight: bold; 
            color: #1e5799; 
            text-transform: uppercase;
        }
        
        .escola-info { 
            font-size: 7pt; 
            color: #7f8c8d; 
            margin-top: 3px;
        }
        
        .titulo-principal {
            text-align: center;
            margin: 10px 0;
        }
        
        .aluno-nome {
            font-size: 12pt;
            font-weight: bold;
            color: #1e5799;
            margin: 5px 0;
        }
        
        .codigo {
            text-align: center;
            margin: 8px 0;
            padding: 4px;
            background: #f8f9fa;
            font-family: monospace;
            font-size: 8pt;
        }
        
        .info-grid {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        
        .info-card {
            flex: 1;
            background: #f8f9fa;
            border-left: 3px solid #1e5799;
            padding: 8px;
        }
        
        .info-card h3 {
            font-size: 9pt;
            color: #1e5799;
            margin-bottom: 5px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 3px;
            font-size: 7.5pt;
        }
        
        .info-label {
            width: 35%;
            font-weight: bold;
            color: #7f8c8d;
        }
        
        .info-value {
            width: 65%;
            color: #2c3e50;
        }
        
        .stats-grid {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            flex: 1;
            color: white;
            padding: 8px;
            text-align: center;
            border-radius: 5px;
        }
        
        .stat-card.blue { background: #1e5799; }
        .stat-card.green { background: #27ae60; }
        .stat-card.orange { background: #e67e22; }
        .stat-card.red { background: #c0392b; }
        
        .stat-number { font-size: 14pt; font-weight: bold; }
        .stat-label { font-size: 6.5pt; }
        
        .tabela-notas {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5pt;
            margin-bottom: 15px;
        }
        
        .tabela-notas th {
            background: #1e5799;
            color: white;
            padding: 5px;
            text-align: center;
            border: 1px solid #2c3e50;
        }
        
        .tabela-notas td {
            border: 1px solid #ddd;
            padding: 4px;
            text-align: center;
        }
        
        .disciplina-nome {
            text-align: left;
            font-weight: bold;
            background: #f8f9fa;
        }
        
        .nota-excelente { background: #d5f4e6; color: #27ae60; font-weight: bold; }
        .nota-muito-bom { background: #d4e6f1; color: #2980b9; font-weight: bold; }
        .nota-bom { background: #fef9e7; color: #f39c12; font-weight: bold; }
        .nota-satisfatorio { background: #fdebd0; color: #e67e22; font-weight: bold; }
        .nota-insuficiente { background: #fadbd8; color: #c0392b; font-weight: bold; }
        
        .status-aprovado { background: #d5f4e6; color: #27ae60; font-weight: bold; padding: 2px 6px; border-radius: 3px; display: inline-block; }
        .status-recuperacao { background: #fef9e7; color: #f39c12; font-weight: bold; padding: 2px 6px; border-radius: 3px; display: inline-block; }
        .status-reprovado { background: #fadbd8; color: #c0392b; font-weight: bold; padding: 2px 6px; border-radius: 3px; display: inline-block; }
        
        .frequencia-boa { color: #27ae60; font-weight: bold; }
        .frequencia-regular { color: #f39c12; font-weight: bold; }
        .frequencia-baixa { color: #c0392b; font-weight: bold; }
        
        .ano-section {
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
            page-break-inside: avoid;
        }
        
        .ano-header {
            background: #2c3e50;
            color: white;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 10pt;
        }
        
        .ano-content {
            padding: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 6.5pt;
            font-weight: bold;
        }
        
        .status-ativa { background: #d5f4e6; color: #27ae60; }
        .status-concluida { background: #d4e6f1; color: #2980b9; }
        .status-cancelada { background: #fadbd8; color: #c0392b; }
        
        .footer {
            text-align: center;
            font-size: 6pt;
            color: #95a5a6;
            border-top: 1px solid #ecf0f1;
            padding-top: 8px;
            margin-top: 10px;
        }
        
        .legenda {
            background: #ecf0f1;
            padding: 8px;
            border-radius: 5px;
            font-size: 6.5pt;
            margin-top: 10px;
        }
        
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin: 20px 0 15px 0;
            gap: 30px;
        }
        
        .assinatura-item {
            flex: 1;
            text-align: center;
        }
        
        .assinatura-linha {
            border-top: 1px solid #2c3e50;
            margin-top: 20px;
            padding-top: 4px;
            font-size: 7pt;
        }
        
        hr {
            margin: 8px 0;
            border: none;
            border-top: 1px solid #ecf0f1;
        }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($dados_aluno['escola_nome'])) . '</div>
    <div class="escola-info">
        ' . htmlspecialchars($dados_aluno['escola_endereco']) . '<br>
        Tel: ' . htmlspecialchars($dados_aluno['escola_telefone']) . ' | Email: ' . htmlspecialchars($dados_aluno['escola_email']) . ' | NIF: ' . htmlspecialchars($dados_aluno['escola_nif']) . '
    </div>
</div>

<div class="titulo-principal">
    <h2>HISTÓRICO ESCOLAR</h2>
    <div class="aluno-nome">' . strtoupper(htmlspecialchars($dados_aluno['estudante_nome'])) . '</div>
    <div class="codigo">Código de Autenticação: <strong>' . $codigo_autenticacao . '</strong></div>
</div>

<!-- Informações do Aluno -->
<div class="info-grid">
    <div class="info-card">
        <h3>DADOS PESSOAIS</h3>
        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value">' . htmlspecialchars($dados_aluno['estudante_nome']) . '</div></div>
        <div class="info-row"><div class="info-label">Matrícula:</div><div class="info-value">' . htmlspecialchars($dados_aluno['estudante_matricula'] ?? '-') . '</div></div>
        <div class="info-row"><div class="info-label">Data Nasc.:</div><div class="info-value">' . ($dados_aluno['data_nascimento'] ?? '-') . '</div></div>
        <div class="info-row"><div class="info-label">BI:</div><div class="info-value">' . htmlspecialchars($dados_aluno['bi'] ?? '-') . '</div></div>
    </div>
    <div class="info-card">
        <h3>CONTATO</h3>
        <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value">' . htmlspecialchars($dados_aluno['telefone'] ?? '-') . '</div></div>
        <div class="info-row"><div class="info-label">Email:</div><div class="info-value">' . htmlspecialchars($dados_aluno['email'] ?? '-') . '</div></div>
        <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value">' . htmlspecialchars($dados_aluno['endereco'] ?? '-') . '</div></div>
    </div>
    <div class="info-card">
        <h3>ENCARREGADO</h3>
        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value">' . htmlspecialchars($dados_aluno['encarregado_nome'] ?? '-') . '</div></div>
        <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value">' . htmlspecialchars($dados_aluno['encarregado_telefone'] ?? '-') . '</div></div>
        <div class="info-row"><div class="info-label">Email:</div><div class="info-value">' . htmlspecialchars($dados_aluno['encarregado_email'] ?? '-') . '</div></div>
    </div>
</div>

' . ($matricula_atual ? '
<div class="info-card" style="margin-bottom: 10px;">
    <h3>MATRÍCULA ATUAL</h3>
    <div class="info-row"><div class="info-label">Turma:</div><div class="info-value"><strong>' . ($matricula_atual['turma_ano'] ? $matricula_atual['turma_ano'] . 'ª ' : '') . htmlspecialchars($matricula_atual['turma_nome'] ?? '-') . '</strong></div></div>
    <div class="info-row"><div class="info-label">Turno:</div><div class="info-value">' . ucfirst($matricula_atual['turno'] ?? '-') . '</div></div>
    <div class="info-row"><div class="info-label">Sala:</div><div class="info-value">' . ($matricula_atual['sala'] ?? '-') . '</div></div>
    <div class="info-row"><div class="info-label">Ano Letivo:</div><div class="info-value">' . htmlspecialchars($matricula_atual['ano_letivo']) . '</div></div>
</div>
' : '') . '

<!-- Estatísticas -->
<div class="stats-grid">
    <div class="stat-card blue"><div class="stat-number">' . $total_matriculas . '</div><div class="stat-label">Total Matrículas</div></div>
    <div class="stat-card green"><div class="stat-number">' . $matriculas_ativas . '</div><div class="stat-label">Ativas</div></div>
    <div class="stat-card orange"><div class="stat-number">' . $matriculas_concluidas . '</div><div class="stat-label">Concluídas</div></div>
    <div class="stat-card red"><div class="stat-number">' . $matriculas_canceladas . '</div><div class="stat-label">Canceladas</div></div>
</div>

<!-- Histórico de Matrículas -->
<div class="info-card" style="margin-bottom: 10px;">
    <h3>HISTÓRICO DE MATRÍCULAS</h3>
    ' . (!empty($historico_matriculas) ? '
    <table class="tabela-notas">
        <thead><tr><th>Ano Letivo</th><th>Turma</th><th>Turno</th><th>Data Matrícula</th><th>Status</th></tr></thead>
        <tbody>' . implode('', array_map(function($mat) {
            return '<tr>
                <td><strong>' . htmlspecialchars($mat['ano_letivo']) . '</strong></td>
                <td>' . ($mat['turma_ano'] ? $mat['turma_ano'] . 'ª ' : '') . htmlspecialchars($mat['turma_nome'] ?? '-') . '</td>
                <td>' . ucfirst($mat['turno'] ?? '-') . '</td>
                <td>' . $mat['data_matricula'] . '</td>
                <td><span class="status-badge status-' . $mat['matricula_status'] . '">' . strtoupper($mat['matricula_status']) . '</span></td>
            </tr>';
        }, $historico_matriculas)) . '</tbody>
    </table>
    ' : '<p>Nenhuma matrícula encontrada.</p>') . '
</div>';

// DESEMPENHO ACADÊMICO
if (!empty($notas_por_ano_disciplina)) {
    $html .= '<div class="info-card" style="margin-bottom: 10px;">
        <h3>DESEMPENHO ACADÊMICO - NOTAS E FREQUÊNCIA</h3>';
    
    foreach ($notas_por_ano_disciplina as $ano => $disciplinas) {
        $ano_presencas = 0;
        $ano_faltas = 0;
        $ano_total_aulas = 0;
        
        $html .= '<div class="ano-section">
            <div class="ano-header">📘 ANO LETIVO: ' . $ano . '</div>
            <div class="ano-content">
                <table class="tabela-notas">
                    <thead>
                        <tr>
                            <th rowspan="2" width="20%">Disciplina</th>
                            <th rowspan="2" width="8%">Código</th>
                            <th colspan="4">MAC (Avaliações)</th>
                            <th rowspan="2" width="8%">Média<br>Final</th>
                            <th rowspan="2" width="12%">Frequência</th>
                            <th rowspan="2" width="12%">Status</th>
                        </tr>
                        <tr>
                            <th width="8%">1º Bim</th>
                            <th width="8%">2º Bim</th>
                            <th width="8%">3º Bim</th>
                            <th width="8%">4º Bim</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        $soma_medias = 0;
        $total_disciplinas = 0;
        
        foreach ($disciplinas as $disciplina_id => $disciplina) {
            $bimestres = $disciplina['bimestres'];
            $media_final = $disciplina['media_final'];
            $soma_medias += $media_final;
            $total_disciplinas++;
            
            $classificacao = classificarNota($media_final);
            $status_aluno = classificarStatusAluno($media_final);
            
            $frequencia_disciplina = isset($frequencia_por_ano_disciplina[$ano][$disciplina_id]) 
                ? $frequencia_por_ano_disciplina[$ano][$disciplina_id] 
                : null;
            
            $percentual_presenca = 0;
            $frequencia_texto = 'Sem dados';
            $frequencia_classe = '';
            
            if ($frequencia_disciplina && $frequencia_disciplina['total_aulas'] > 0) {
                $percentual_presenca = ($frequencia_disciplina['total_presencas'] / $frequencia_disciplina['total_aulas']) * 100;
                $freq_class = classificarFrequencia($percentual_presenca);
                $frequencia_texto = $freq_class['texto'] . ' (' . round($percentual_presenca, 1) . '%)';
                $frequencia_classe = $freq_class['classe'];
                $ano_presencas += $frequencia_disciplina['total_presencas'];
                $ano_faltas += $frequencia_disciplina['total_faltas'];
                $ano_total_aulas += $frequencia_disciplina['total_aulas'];
            }
            
            $html .= '<tr>
                <td class="disciplina-nome">' . htmlspecialchars($disciplina['disciplina_nome']) . '</td>
                <td>' . htmlspecialchars($disciplina['disciplina_codigo']) . '</td>
                <td class="' . (isset($bimestres[1]) && $bimestres[1] ? classificarNota($bimestres[1])['classe'] : '') . '">' . (isset($bimestres[1]) && $bimestres[1] ? number_format($bimestres[1], 1) : '---') . '</td>
                <td class="' . (isset($bimestres[2]) && $bimestres[2] ? classificarNota($bimestres[2])['classe'] : '') . '">' . (isset($bimestres[2]) && $bimestres[2] ? number_format($bimestres[2], 1) : '---') . '</td>
                <td class="' . (isset($bimestres[3]) && $bimestres[3] ? classificarNota($bimestres[3])['classe'] : '') . '">' . (isset($bimestres[3]) && $bimestres[3] ? number_format($bimestres[3], 1) : '---') . '</td>
                <td class="' . (isset($bimestres[4]) && $bimestres[4] ? classificarNota($bimestres[4])['classe'] : '') . '">' . (isset($bimestres[4]) && $bimestres[4] ? number_format($bimestres[4], 1) : '---') . '</td>
                <td class="' . $classificacao['classe'] . '"><strong>' . number_format($media_final, 1) . '</strong></td>
                <td class="' . $frequencia_classe . '">' . $frequencia_texto . '</td>
                <td><span class="' . $status_aluno['classe'] . '">' . $status_aluno['icon'] . ' ' . $status_aluno['texto'] . '</span></td>
            </tr>';
        }
        
        if ($total_disciplinas > 0) {
            $media_geral_ano = $soma_medias / $total_disciplinas;
            $status_ano = classificarStatusAluno($media_geral_ano);
            
            $html .= '<tr style="background: #ecf0f1; font-weight: bold;">
                <td colspan="6" class="text-right"><strong>MÉDIA GERAL DO ANO:</strong></td>
                <td class="' . classificarNota($media_geral_ano)['classe'] . '"><strong>' . number_format($media_geral_ano, 1) . '</strong></td>
                <td>' . ($ano_total_aulas > 0 ? '<span class="' . classificarFrequencia(($ano_presencas / $ano_total_aulas) * 100)['classe'] . '">' . classificarFrequencia(($ano_presencas / $ano_total_aulas) * 100)['texto'] . ' (' . round(($ano_presencas / $ano_total_aulas) * 100, 1) . '%)</span>' : 'Sem dados') . '</td>
                <td><span class="' . $status_ano['classe'] . '">' . $status_ano['icon'] . ' ' . $status_ano['texto'] . '</span></td>
            </tr>';
        }
        
        $html .= '</tbody>
                </table>
            </div>
        </div>';
    }
    
    $html .= '<div class="legenda">
        <strong>📌 LEGENDA:</strong><br>
        🟢 Excelente (18-20) | 🔵 Muito Bom (14-17) | 🟡 Bom (10-13) | 🟠 Satisfatório (7-9) | 🔴 Insuficiente (0-6)<br>
        ✅ Aprovado (Média ≥ 10) | ⚠️ Recuperação (Média 7-9) | ❌ Reprovado (Média < 7)<br>
        📊 Frequência: Boa (≥75%) | Regular (50-74%) | Baixa (<50%)
    </div>
    </div>';
} else {
    $html .= '<div class="info-card"><h3>DESEMPENHO ACADÊMICO</h3><p>Nenhuma nota registrada para este aluno.</p></div>';
}

// Observações
$html .= '
<div class="info-card" style="margin-bottom: 10px;">
    <h3>INFORMAÇÕES ADICIONAIS</h3>
    <ul style="margin-left: 20px; font-size: 7pt;">
        <li><strong>MAC (Média de Aproveitamento e Conhecimento):</strong> Média das avaliações realizadas durante o bimestre.</li>
        <li><strong>Critério de Aprovação:</strong> Média final igual ou superior a 10 valores com frequência mínima de 75%.</li>
        <li><strong>Frequência:</strong> Calculada com base nas chamadas realizadas durante o ano letivo.</li>
        <li>Este documento é um comprovativo oficial do histórico escolar do aluno.</li>
        <li>Em caso de dúvidas, contactar a secretaria escolar para validação.</li>
    </ul>
</div>

<!-- Assinaturas -->
<div class="assinaturas">
    <div class="assinatura-item">
        <div class="assinatura-linha">_________________________</div>
        <div>Secretaria Escolar</div>
        <div style="font-size: 6pt;">Carimbo e Assinatura</div>
    </div>
    <div class="assinatura-item">
        <div class="assinatura-linha">_________________________</div>
        <div>Direção Pedagógica</div>
        <div style="font-size: 6pt;">Carimbo e Assinatura</div>
    </div>
</div>

<div class="footer">
    Documento emitido eletronicamente por SIGE Angola - Sistema de Gestão Escolar<br>
    Emissão: ' . date('d/m/Y \à\s H:i:s') . ' | Página {PAGE_NUM} de {PAGE_COUNT}
</div>

</body>
</html>';

// Gerar PDF
try {
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $nome_arquivo = 'historico_escolar_' . ($dados_aluno['estudante_matricula'] ?? $estudante_id) . '_' . date('Ymd') . '.pdf';
    
    if (ob_get_level()) ob_end_clean();
    $dompdf->stream($nome_arquivo, ['Attachment' => false]);
    exit;
    
} catch (Exception $e) {
    die('Erro ao gerar PDF: ' . $e->getMessage());
}
?>