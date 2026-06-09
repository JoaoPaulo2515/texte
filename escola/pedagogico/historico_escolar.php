<?php
// escola/pedagogico/historico_escolar.php - Histórico Escolar do Aluno

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
    header('Location: listar_alunos.php');
    exit;
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
        t.sala,
        al.id as ano_letivo_id,
        al.ano as ano_letivo_ano
    FROM matriculas m
    LEFT JOIN turmas t ON t.id = m.turma_id
    LEFT JOIN anos_letivos al ON al.id = m.ano_letivo
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
        n.exame_oral,
        n.exame_escrito,
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
        d.carga_horaria,
        al.ano as ano_letivo
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    INNER JOIN ano_letivo al ON al.id = n.ano_letivo_id
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

// Classificar nota (MAC)
function classificarNota($nota) {
    if ($nota >= 18) return ['texto' => 'Excelente', 'classe' => 'nota-excelente', 'icon' => '🌟'];
    if ($nota >= 14) return ['texto' => 'Muito Bom', 'classe' => 'nota-muito-bom', 'icon' => '⭐'];
    if ($nota >= 10) return ['texto' => 'Bom', 'classe' => 'nota-bom', 'icon' => '✅'];
    if ($nota >= 7) return ['texto' => 'Satisfatório', 'classe' => 'nota-satisfatorio', 'icon' => '⚠️'];
    return ['texto' => 'Insuficiente', 'classe' => 'nota-insuficiente', 'icon' => '❌'];
}

// Classificar status do aluno
function classificarStatusAluno($media_final) {
    if ($media_final >= 10) return ['texto' => 'Aprovado', 'classe' => 'status-aprovado', 'icon' => '✅'];
    if ($media_final >= 7) return ['texto' => 'Recuperação', 'classe' => 'status-recuperacao', 'icon' => '⚠️'];
    return ['texto' => 'Reprovado', 'classe' => 'status-reprovado', 'icon' => '❌'];
}

// Calcular percentual de presença
function calcularPresenca($total_aulas, $total_presencas, $total_faltas) {
    if ($total_aulas == 0) return 0;
    $percentual = ($total_presencas / $total_aulas) * 100;
    return round($percentual, 1);
}

// Classificar frequência
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
            'mac' => [],
            'npt' => [],
            'exame_normal' => null,
            'exame_recurso' => null,
            'media_final' => 0,
            'nota_status' => ''
        ];
    }
    
    $bimestre = $nota['bimestre'];
    $notas_por_ano_disciplina[$ano][$disciplina_id]['bimestres'][$bimestre] = [
        'mac' => $nota['mac'],
        'npt' => $nota['npt'],
        'exame_normal' => $nota['exame_normal'],
        'media_parcial' => $nota['media_parcial']
    ];
    $notas_por_ano_disciplina[$ano][$disciplina_id]['mac'][$bimestre] = $nota['mac'];
    $notas_por_ano_disciplina[$ano][$disciplina_id]['npt'][$bimestre] = $nota['npt'];
    $notas_por_ano_disciplina[$ano][$disciplina_id]['media_final'] = $nota['media_final'];
    $notas_por_ano_disciplina[$ano][$disciplina_id]['nota_status'] = $nota['nota_status'];
}

// Agrupar frequências por ano/disciplina/bimestre
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
            'bimestres' => [],
            'total_presencas' => 0,
            'total_faltas' => 0,
            'total_aulas' => 0
        ];
    }
    
    if (!isset($frequencia_por_ano_disciplina[$ano][$disciplina_id]['bimestres'][$bimestre])) {
        $frequencia_por_ano_disciplina[$ano][$disciplina_id]['bimestres'][$bimestre] = [
            'presencas' => 0,
            'faltas' => 0,
            'total' => 0
        ];
    }
    
    if ($freq['status'] == 'presente') {
        $frequencia_por_ano_disciplina[$ano][$disciplina_id]['bimestres'][$bimestre]['presencas']++;
        $frequencia_por_ano_disciplina[$ano][$disciplina_id]['total_presencas']++;
    } else {
        $frequencia_por_ano_disciplina[$ano][$disciplina_id]['bimestres'][$bimestre]['faltas']++;
        $frequencia_por_ano_disciplina[$ano][$disciplina_id]['total_faltas']++;
    }
    $frequencia_por_ano_disciplina[$ano][$disciplina_id]['bimestres'][$bimestre]['total']++;
    $frequencia_por_ano_disciplina[$ano][$disciplina_id]['total_aulas']++;
}

// Gerar código de autenticação
$codigo_autenticacao = strtoupper(substr(md5($estudante_id . date('Ymd')), 0, 16));
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico Escolar - <?php echo htmlspecialchars($dados_aluno['estudante_nome']); ?></title>
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
        
        /* Botões */
        .btn-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
        }
        
        .btn-primary { background: linear-gradient(135deg, #1e5799, #2c3e50); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .btn-success { background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .btn-info { background: linear-gradient(135deg, #3498db, #2980b9); color: white; }
        
        /* Cards */
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
            padding: 15px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Grid de informações */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            border-left: 4px solid #1e5799;
        }
        
        .info-card h3 {
            color: #1e5799;
            margin-bottom: 10px;
            font-size: 14px;
            text-transform: uppercase;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .info-label {
            width: 35%;
            font-weight: 600;
            color: #7f8c8d;
        }
        
        .info-value {
            width: 65%;
            color: #2c3e50;
        }
        
        /* Estatísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-card.blue { background: linear-gradient(135deg, #1e5799, #2980b9); }
        .stat-card.green { background: linear-gradient(135deg, #27ae60, #2ecc71); }
        .stat-card.orange { background: linear-gradient(135deg, #e67e22, #f39c12); }
        .stat-card.red { background: linear-gradient(135deg, #c0392b, #e74c3c); }
        
        .stat-number { font-size: 28px; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 11px; opacity: 0.9; }
        
        /* Tabela de Notas */
        .tabela-notas {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-bottom: 20px;
        }
        
        .tabela-notas th {
            background: #1e5799;
            color: white;
            padding: 8px;
            text-align: center;
            font-weight: bold;
            border: 1px solid #2c3e50;
        }
        
        .tabela-notas td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: center;
            vertical-align: middle;
        }
        
        .tabela-notas tr:hover {
            background: #f8f9fa;
        }
        
        .disciplina-nome {
            text-align: left;
            font-weight: bold;
            background: #f8f9fa;
        }
        
        /* Cores das Notas */
        .nota-excelente { background: #d5f4e6; color: #27ae60; font-weight: bold; }
        .nota-muito-bom { background: #d4e6f1; color: #2980b9; font-weight: bold; }
        .nota-bom { background: #fef9e7; color: #f39c12; font-weight: bold; }
        .nota-satisfatorio { background: #fdebd0; color: #e67e22; font-weight: bold; }
        .nota-insuficiente { background: #fadbd8; color: #c0392b; font-weight: bold; }
        
        /* Status */
        .status-aprovado { background: #d5f4e6; color: #27ae60; font-weight: bold; padding: 3px 8px; border-radius: 5px; display: inline-block; }
        .status-recuperacao { background: #fef9e7; color: #f39c12; font-weight: bold; padding: 3px 8px; border-radius: 5px; display: inline-block; }
        .status-reprovado { background: #fadbd8; color: #c0392b; font-weight: bold; padding: 3px 8px; border-radius: 5px; display: inline-block; }
        
        /* Frequência */
        .frequencia-boa { color: #27ae60; font-weight: bold; }
        .frequencia-regular { color: #f39c12; font-weight: bold; }
        .frequencia-baixa { color: #c0392b; font-weight: bold; }
        
        /* Ano Section */
        .ano-section {
            margin-bottom: 25px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .ano-header {
            background: linear-gradient(135deg, #2c3e50, #1e5799);
            color: white;
            padding: 12px 15px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ano-header:hover {
            opacity: 0.95;
        }
        
        .ano-content {
            display: none;
            padding: 15px;
            background: white;
        }
        
        .ano-content.active {
            display: block;
        }
        
        .media-ano {
            background: #ecf0f1;
            padding: 10px;
            margin-top: 15px;
            border-radius: 5px;
            text-align: right;
            font-weight: bold;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-ativa { background: #d5f4e6; color: #27ae60; }
        .status-concluida { background: #d4e6f1; color: #2980b9; }
        .status-cancelada { background: #fadbd8; color: #c0392b; }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #7f8c8d;
            font-size: 11px;
            border-top: 1px solid #ecf0f1;
            margin-top: 20px;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
        }
        
        .legenda {
            background: #ecf0f1;
            padding: 10px;
            border-radius: 5px;
            font-size: 10px;
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .info-grid, .stats-grid { grid-template-columns: 1fr; }
            .btn-group { flex-direction: column; }
            .tabela-notas { font-size: 9px; }
            .tabela-notas th, .tabela-notas td { padding: 4px; }
        }
        
        @media print {
            body { background: white; padding: 0; }
            .btn-group { display: none; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .ano-content { display: block !important; }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Botões -->
    <div class="btn-group">
        <button onclick="window.print()" class="btn btn-primary">🖨️ Imprimir Histórico</button>
        <a href="gerar_historico_escolar_pdf.php?id=<?php echo $estudante_id; ?>" class="btn btn-success" target="_blank">📄 Gerar PDF</a>
        <a href="javascript:history.back()" class="btn btn-info">← Voltar</a>
    </div>
    
    <!-- Cabeçalho -->
    <div class="card">
        <div class="card-header">📚 HISTÓRICO ESCOLAR</div>
        <div class="card-body">
            <div style="text-align: center;">
                <h2 style="color: #1e5799;"><?php echo strtoupper(htmlspecialchars($dados_aluno['escola_nome'])); ?></h2>
                <p style="color: #7f8c8d; font-size: 12px;">
                    <?php echo htmlspecialchars($dados_aluno['escola_endereco']); ?><br>
                    📞 Tel: <?php echo htmlspecialchars($dados_aluno['escola_telefone']); ?> | 
                    ✉ Email: <?php echo htmlspecialchars($dados_aluno['escola_email']); ?> | 
                    📄 NIF: <?php echo htmlspecialchars($dados_aluno['escola_nif']); ?>
                </p>
                <hr style="margin: 15px 0;">
                <h3>HISTÓRICO ESCOLAR DO ALUNO</h3>
                <p style="font-size: 18px; font-weight: bold; color: #1e5799;"><?php echo strtoupper(htmlspecialchars($dados_aluno['estudante_nome'])); ?></p>
                <p style="font-size: 12px; color: #7f8c8d;">Código de Autenticação: <strong><?php echo $codigo_autenticacao; ?></strong></p>
            </div>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="info-grid">
        <div class="info-card">
            <h3>📋 DADOS PESSOAIS</h3>
            <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><strong><?php echo htmlspecialchars($dados_aluno['estudante_nome']); ?></strong></div></div>
            <div class="info-row"><div class="info-label">Matrícula:</div><div class="info-value"><?php echo htmlspecialchars($dados_aluno['estudante_matricula'] ?? '-'); ?></div></div>
            <div class="info-row"><div class="info-label">Data Nasc.:</div><div class="info-value"><?php echo $dados_aluno['data_nascimento'] ?? '-'; ?></div></div>
            <div class="info-row"><div class="info-label">BI:</div><div class="info-value"><?php echo htmlspecialchars($dados_aluno['bi'] ?? '-'); ?></div></div>
        </div>
        <div class="info-card">
            <h3>📞 CONTATO</h3>
            <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo htmlspecialchars($dados_aluno['telefone'] ?? '-'); ?></div></div>
            <div class="info-row"><div class="info-label">Email:</div><div class="info-value"><?php echo htmlspecialchars($dados_aluno['email'] ?? '-'); ?></div></div>
            <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value"><?php echo htmlspecialchars($dados_aluno['endereco'] ?? '-'); ?></div></div>
        </div>
        <div class="info-card">
            <h3>👨‍👩‍👧 ENCARREGADO</h3>
            <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($dados_aluno['encarregado_nome'] ?? '-'); ?></div></div>
            <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo htmlspecialchars($dados_aluno['encarregado_telefone'] ?? '-'); ?></div></div>
            <div class="info-row"><div class="info-label">Email:</div><div class="info-value"><?php echo htmlspecialchars($dados_aluno['encarregado_email'] ?? '-'); ?></div></div>
        </div>
    </div>
    
    <!-- Matrícula Atual -->
    <?php if ($matricula_atual): ?>
    <div class="card">
        <div class="card-header">🎓 MATRÍCULA ATUAL</div>
        <div class="card-body">
            <div class="info-grid" style="margin-bottom: 0;">
                <div>
                    <div class="info-row"><div class="info-label">Turma:</div><div class="info-value"><strong><?php echo ($matricula_atual['turma_ano'] ? $matricula_atual['turma_ano'] . 'ª ' : '') . htmlspecialchars($matricula_atual['turma_nome'] ?? '-'); ?></strong></div></div>
                    <div class="info-row"><div class="info-label">Turno:</div><div class="info-value"><?php echo ucfirst($matricula_atual['turno'] ?? '-'); ?></div></div>
                </div>
                <div>
                    <div class="info-row"><div class="info-label">Sala:</div><div class="info-value"><?php echo $matricula_atual['sala'] ?? '-'; ?></div></div>
                    <div class="info-row"><div class="info-label">Ano Letivo:</div><div class="info-value"><?php echo htmlspecialchars($matricula_atual['ano_letivo']); ?></div></div>
                </div>
                <div>
                    <div class="info-row"><div class="info-label">Data Matrícula:</div><div class="info-value"><?php echo $matricula_atual['data_matricula']; ?></div></div>
                    <div class="info-row"><div class="info-label">Status:</div><div class="info-value"><span class="status-badge status-ativa">ATIVA</span></div></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card blue"><div class="stat-number"><?php echo $total_matriculas; ?></div><div class="stat-label">Total Matrículas</div></div>
        <div class="stat-card green"><div class="stat-number"><?php echo $matriculas_ativas; ?></div><div class="stat-label">Matrículas Ativas</div></div>
        <div class="stat-card orange"><div class="stat-number"><?php echo $matriculas_concluidas; ?></div><div class="stat-label">Anos Concluídos</div></div>
        <div class="stat-card red"><div class="stat-number"><?php echo $matriculas_canceladas; ?></div><div class="stat-label">Canceladas</div></div>
    </div>
    
    <!-- DESEMPENHO ACADÊMICO - NOTAS E FREQUÊNCIA -->
    <?php if (!empty($notas_por_ano_disciplina)): ?>
    <div class="card">
        <div class="card-header">🎓 DESEMPENHO ACADÊMICO - NOTAS E FREQUÊNCIA</div>
        <div class="card-body">
            <?php foreach ($notas_por_ano_disciplina as $ano => $disciplinas): 
                $ano_presencas = 0;
                $ano_faltas = 0;
                $ano_total_aulas = 0;
            ?>
            <div class="ano-section">
                <div class="ano-header" onclick="toggleAno(this)">
                    <span>📘 ANO LETIVO: <?php echo $ano; ?></span>
                    <span>▼</span>
                </div>
                <div class="ano-content">
                    <table class="tabela-notas">
                        <thead>
                            <tr>
                                <th rowspan="2" width="18%">Disciplina</th>
                                <th rowspan="2" width="6%">Código</th>
                                <th colspan="4">MAC (Avaliações)</th>
                                <th rowspan="2" width="7%">Média<br>Final</th>
                                <th rowspan="2" width="10%">Frequência</th>
                                <th rowspan="2" width="10%">Status</th>
                            </tr>
                            <tr>
                                <th width="7%">1º Bim</th>
                                <th width="7%">2º Bim</th>
                                <th width="7%">3º Bim</th>
                                <th width="7%">4º Bim</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $soma_medias = 0;
                            $total_disciplinas = 0;
                            
                            foreach ($disciplinas as $disciplina_id => $disciplina):
                                $bimestres = $disciplina['bimestres'];
                                $media_final = $disciplina['media_final'];
                                $soma_medias += $media_final;
                                $total_disciplinas++;
                                
                                $classificacao = classificarNota($media_final);
                                $status_aluno = classificarStatusAluno($media_final);
                                
                                // Buscar frequência para esta disciplina
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
                            ?>
                            <tr>
                                <td class="disciplina-nome"><?php echo htmlspecialchars($disciplina['disciplina_nome']); ?></td>
                                <td><?php echo htmlspecialchars($disciplina['disciplina_codigo']); ?></td>
                                
                                <!-- 1º Bimestre -->
                                <td class="<?php echo isset($bimestres[1]['mac']) && $bimestres[1]['mac'] ? classificarNota($bimestres[1]['mac'])['classe'] : ''; ?>">
                                    <?php echo isset($bimestres[1]['mac']) && $bimestres[1]['mac'] ? number_format($bimestres[1]['mac'], 1) : '---'; ?>
                                </td>
                                
                                <!-- 2º Bimestre -->
                                <td class="<?php echo isset($bimestres[2]['mac']) && $bimestres[2]['mac'] ? classificarNota($bimestres[2]['mac'])['classe'] : ''; ?>">
                                    <?php echo isset($bimestres[2]['mac']) && $bimestres[2]['mac'] ? number_format($bimestres[2]['mac'], 1) : '---'; ?>
                                </td>
                                
                                <!-- 3º Bimestre -->
                                <td class="<?php echo isset($bimestres[3]['mac']) && $bimestres[3]['mac'] ? classificarNota($bimestres[3]['mac'])['classe'] : ''; ?>">
                                    <?php echo isset($bimestres[3]['mac']) && $bimestres[3]['mac'] ? number_format($bimestres[3]['mac'], 1) : '---'; ?>
                                </td>
                                
                                <!-- 4º Bimestre -->
                                <td class="<?php echo isset($bimestres[4]['mac']) && $bimestres[4]['mac'] ? classificarNota($bimestres[4]['mac'])['classe'] : ''; ?>">
                                    <?php echo isset($bimestres[4]['mac']) && $bimestres[4]['mac'] ? number_format($bimestres[4]['mac'], 1) : '---'; ?>
                                </td>
                                
                                <!-- Média Final -->
                                <td class="<?php echo $classificacao['classe']; ?>">
                                    <strong><?php echo number_format($media_final, 1); ?></strong>
                                </td>
                                
                                <!-- Frequência -->
                                <td class="<?php echo $frequencia_classe; ?>">
                                    <?php echo $frequencia_texto; ?>
                                </td>
                                
                                <!-- Status -->
                                <td>
                                    <span class="<?php echo $status_aluno['classe']; ?>">
                                        <?php echo $status_aluno['icon'] . ' ' . $status_aluno['texto']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if ($total_disciplinas > 0): 
                                $media_geral_ano = $soma_medias / $total_disciplinas;
                                $status_ano = classificarStatusAluno($media_geral_ano);
                            ?>
                            <tr style="background: #ecf0f1; font-weight: bold;">
                                <td colspan="6" style="text-align: right;"><strong>MÉDIA GERAL DO ANO:</strong></td>
                                <td class="<?php echo classificarNota($media_geral_ano)['classe']; ?>"><strong><?php echo number_format($media_geral_ano, 1); ?></strong></td>
                                <td>
                                    <?php if ($ano_total_aulas > 0): 
                                        $perc_ano = ($ano_presencas / $ano_total_aulas) * 100;
                                        $freq_ano = classificarFrequencia($perc_ano);
                                    ?>
                                        <span class="<?php echo $freq_ano['classe']; ?>">
                                            <?php echo $freq_ano['texto']; ?> (<?php echo round($perc_ano, 1); ?>%)
                                        </span>
                                    <?php else: ?>
                                        Sem dados
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?php echo $status_ano['classe']; ?>"><?php echo $status_ano['icon'] . ' ' . $status_ano['texto']; ?></span></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Legenda -->
            <div class="legenda">
                <strong>📌 LEGENDA:</strong><br>
                🟢 <strong>Excelente (18-20)</strong> | 🔵 <strong>Muito Bom (14-17)</strong> | 🟡 <strong>Bom (10-13)</strong> | 🟠 <strong>Satisfatório (7-9)</strong> | 🔴 <strong>Insuficiente (0-6)</strong><br>
                ✅ <strong>Aprovado</strong> (Média ≥ 10) | ⚠️ <strong>Recuperação</strong> (Média 7-9) | ❌ <strong>Reprovado</strong> (Média < 7)<br>
                📊 <strong>Frequência:</strong> Boa (≥75%) | Regular (50-74%) | Baixa (<50%)
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header">🎓 DESEMPENHO ACADÊMICO</div>
        <div class="card-body">
            <div class="alert-warning">Nenhuma nota registrada para este aluno.</div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Observações -->
    <div class="card">
        <div class="card-header">📝 INFORMAÇÕES ADICIONAIS</div>
        <div class="card-body">
            <ul style="margin-left: 20px; color: #555; font-size: 12px; line-height: 1.6;">
                <li><strong>MAC (Média de Aproveitamento e Conhecimento):</strong> Média das avaliações realizadas durante o bimestre.</li>
                <li><strong>Critério de Aprovação:</strong> Média final igual ou superior a 10 valores com frequência mínima de 75%.</li>
                <li><strong>Frequência:</strong> Calculada com base nas chamadas realizadas durante o ano letivo.</li>
                <li>Este documento é um comprovativo oficial do histórico escolar do aluno.</li>
                <li>Em caso de dúvidas, contactar a secretaria escolar para validação.</li>
            </ul>
        </div>
    </div>
    
    <!-- Assinaturas -->
    <div class="card">
        <div class="card-body">
            <div style="display: flex; justify-content: space-between; gap: 40px;">
                <div style="text-align: center; flex: 1;">
                    <div style="border-top: 1px solid #333; padding-top: 8px;">
                        <strong>Secretaria Escolar</strong>
                    </div>
                    <p style="font-size: 10px; color: #7f8c8d;">Carimbo e Assinatura</p>
                </div>
                <div style="text-align: center; flex: 1;">
                    <div style="border-top: 1px solid #333; padding-top: 8px;">
                        <strong>Direção Pedagógica</strong>
                    </div>
                    <p style="font-size: 10px; color: #7f8c8d;">Carimbo e Assinatura</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
        <p>Documento emitido eletronicamente por SIGE Angola - Sistema Integrado de Gestão Escolar</p>
        <p>Data de Emissão: <?php echo date('d/m/Y \à\s H:i:s'); ?></p>
        <p>Código de Autenticação: <?php echo $codigo_autenticacao; ?></p>
    </div>
</div>

<script>
    function toggleAno(element) {
        let content = element.nextElementSibling;
        content.classList.toggle('active');
        let arrow = element.querySelector('span:last-child');
        if (content.classList.contains('active')) {
            arrow.innerHTML = '▲';
        } else {
            arrow.innerHTML = '▼';
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        let firstAno = document.querySelector('.ano-content');
        if (firstAno) {
            firstAno.classList.add('active');
            let firstHeader = document.querySelector('.ano-header');
            if (firstHeader) {
                let arrow = firstHeader.querySelector('span:last-child');
                if (arrow) arrow.innerHTML = '▲';
            }
        }
    });
</script>
</body>
</html>