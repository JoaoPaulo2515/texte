<?php
// escola/pedagogico/visualizar_aluno.php - Visualizar Aluno

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// ============================================
// VERIFICAR PERMISSÃO
// ============================================
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
    AND u.status = 'ativo'
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    include __DIR__ . '/access_denied.php';
    exit;
}

$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$aluno_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($aluno_id <= 0) {
    header('Location: listar_alunos.php');
    exit;
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql_aluno = "
    SELECT 
        e.*,
        DATE_FORMAT(e.data_nascimento, '%d/%m/%Y') as data_nascimento_formatada,
        TIMESTAMPDIFF(YEAR, e.data_nascimento, CURDATE()) as idade,
        m.id as matricula_id,
        m.numero_processo,
        m.data_matricula,
        m.status as matricula_status,
        t.id as turma_id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala,
        esc.nome as escola_nome
    FROM estudantes e
    INNER JOIN matriculas m ON m.estudante_id = e.id
    INNER JOIN turmas t ON t.id = m.turma_id
    INNER JOIN escolas esc ON esc.id = t.escola_id
    WHERE e.id = :aluno_id 
    AND m.ano_letivo = :ano_letivo_id
    AND m.status = 'ativa'
    LIMIT 1
";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: listar_alunos.php?error=aluno_nao_encontrado');
    exit;
}

// ============================================
// BUSCAR DISCIPLINAS E NOTAS DO ALUNO
// ============================================
$sql_disciplinas = "
    SELECT 
        d.id,
        d.nome,
        d.codigo,
        d.carga_horaria,
        n.id as nota_id,
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
        n.status as nota_status,
        f.nome as professor_nome
    FROM disciplinas d
    LEFT JOIN notas n ON n.disciplina_id = d.id 
        AND n.estudante_id = :aluno_id
        AND n.ano_letivo_id = :ano_letivo_id
    LEFT JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id AND pdt.turma_id = :turma_id
    LEFT JOIN funcionarios f ON f.id = pdt.professor_id
    WHERE d.id IN (
        SELECT DISTINCT disciplina_id 
        FROM professor_disciplina_turma 
        WHERE turma_id = :turma_id1
    )
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([
    ':aluno_id' => $aluno_id,
    ':turma_id' => $aluno['turma_id'],
    ':turma_id1' => $aluno['turma_id'],
    ':ano_letivo_id' => $ano_letivo_id
]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Organizar notas por disciplina e bimestre
$notas_organizadas = [];
foreach ($disciplinas as $disciplina) {
    $disciplina_id = $disciplina['id'];
    if (!isset($notas_organizadas[$disciplina_id])) {
        $notas_organizadas[$disciplina_id] = [
            'nome' => $disciplina['nome'],
            'codigo' => $disciplina['codigo'],
            'professor' => $disciplina['professor_nome'],
            'notas' => []
        ];
    }
    if ($disciplina['bimestre']) {
        $notas_organizadas[$disciplina_id]['notas'][$disciplina['bimestre']] = [
            'mac' => $disciplina['mac'],
            'npt' => $disciplina['npt'],
            'exame_normal' => $disciplina['exame_normal'],
            'exame_recurso' => $disciplina['exame_recurso'],
            'media_parcial' => $disciplina['media_parcial'],
            'media_final' => $disciplina['media_final'],
            'status' => $disciplina['nota_status']
        ];
    }
}

// ============================================
// BUSCAR FREQUÊNCIA DO ALUNO (USANDO TABELA chamada)
// ============================================
$sql_frequencia = "
    SELECT 
        COUNT(CASE WHEN c.status = 'presente' THEN 1 END) as total_presencas,
        COUNT(CASE WHEN c.status = 'falta' THEN 1 END) as total_faltas,
        COUNT(CASE WHEN c.status = 'justificado' THEN 1 END) as total_faltas_justificadas,
        COUNT(CASE WHEN c.status = 'atraso' THEN 1 END) as total_atrasos,
        COUNT(*) as total_aulas,
        ROUND((COUNT(CASE WHEN c.status = 'presente' THEN 1 END) / NULLIF(COUNT(*), 0)) * 100, 1) as percentual_presenca,
        ROUND(AVG(CASE WHEN c.minutos_atraso > 0 THEN c.minutos_atraso ELSE NULL END), 1) as media_minutos_atraso,
        SUM(c.minutos_atraso) as total_minutos_atraso
    FROM chamada c
    INNER JOIN matriculas m ON m.id = c.estudante_id
    WHERE m.estudante_id = :aluno_id 
    AND c.ano_letivo_id = :ano_letivo_id
";
$stmt_frequencia = $conn->prepare($sql_frequencia);
$stmt_frequencia->execute([
    ':aluno_id' => $aluno_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$frequencia = $stmt_frequencia->fetch(PDO::FETCH_ASSOC);

if (!$frequencia || $frequencia['total_aulas'] == 0) {
    $frequencia = [
        'total_presencas' => 0,
        'total_faltas' => 0,
        'total_faltas_justificadas' => 0,
        'total_atrasos' => 0,
        'total_aulas' => 0,
        'percentual_presenca' => 0,
        'media_minutos_atraso' => 0,
        'total_minutos_atraso' => 0
    ];
}

// ============================================
// BUSCAR FREQUÊNCIA POR BIMESTRE
// ============================================
$sql_frequencia_bimestre = "
    SELECT 
        c.bimestre,
        COUNT(CASE WHEN c.status = 'presente' THEN 1 END) as presencas,
        COUNT(CASE WHEN c.status = 'falta' THEN 1 END) as faltas,
        COUNT(CASE WHEN c.status = 'justificado' THEN 1 END) as faltas_justificadas,
        COUNT(CASE WHEN c.status = 'atraso' THEN 1 END) as atrasos,
        COUNT(*) as total_aulas,
        ROUND((COUNT(CASE WHEN c.status = 'presente' THEN 1 END) / NULLIF(COUNT(*), 0)) * 100, 1) as percentual
    FROM chamada c
    INNER JOIN matriculas m ON m.id = c.estudante_id
    WHERE m.estudante_id = :aluno_id 
    AND c.ano_letivo_id = :ano_letivo_id
    GROUP BY c.bimestre
    ORDER BY c.bimestre ASC
";
$stmt_frequencia_bimestre = $conn->prepare($sql_frequencia_bimestre);
$stmt_frequencia_bimestre->execute([
    ':aluno_id' => $aluno_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$frequencia_bimestre = $stmt_frequencia_bimestre->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR FALTAS CONSECUTIVAS (VERSÃO COMPATÍVEL COM MARIADB)
// ============================================
$faltas_consecutivas = null;

// Buscar todas as faltas do aluno
$sql_buscar_faltas = "
    SELECT 
        c.data_aula,
        c.status,
        c.estudante_id
    FROM chamada c
    INNER JOIN matriculas m ON m.id = c.estudante_id
    WHERE m.estudante_id = :aluno_id 
    AND c.ano_letivo_id = :ano_letivo_id
    AND c.status = 'falta'
    ORDER BY c.data_aula ASC
";
$stmt_buscar_faltas = $conn->prepare($sql_buscar_faltas);
$stmt_buscar_faltas->execute([
    ':aluno_id' => $aluno_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$faltas = $stmt_buscar_faltas->fetchAll(PDO::FETCH_ASSOC);

// Calcular a maior sequência de faltas consecutivas
$maior_sequencia = 0;
$sequencia_atual = 1;
$inicio_sequencia = null;
$fim_sequencia = null;

if (count($faltas) > 0) {
    for ($i = 0; $i < count($faltas); $i++) {
        if ($i > 0) {
            $data_anterior = new DateTime($faltas[$i - 1]['data_aula']);
            $data_atual = new DateTime($faltas[$i]['data_aula']);
            $diferenca = $data_anterior->diff($data_atual)->days;
            
            if ($diferenca == 1) {
                $sequencia_atual++;
            } else {
                if ($sequencia_atual > $maior_sequencia) {
                    $maior_sequencia = $sequencia_atual;
                    $inicio_sequencia = $faltas[$i - $sequencia_atual]['data_aula'];
                    $fim_sequencia = $faltas[$i - 1]['data_aula'];
                }
                $sequencia_atual = 1;
            }
        }
    }
    
    // Verificar a última sequência
    if ($sequencia_atual > $maior_sequencia) {
        $maior_sequencia = $sequencia_atual;
        $inicio_sequencia = $faltas[count($faltas) - $sequencia_atual]['data_aula'];
        $fim_sequencia = $faltas[count($faltas) - 1]['data_aula'];
    }
    
    $faltas_consecutivas = [
        'faltas_consecutivas' => $maior_sequencia,
        'inicio_faltas' => $inicio_sequencia,
        'fim_faltas' => $fim_sequencia
    ];
}

// ============================================
// BUSCAR HISTÓRICO DE PAGAMENTOS
// ============================================
$sql_pagamentos = "
    SELECT 
        COUNT(*) as total_mensalidades,
        SUM(CASE WHEN status = 'pago' THEN valor_pago ELSE 0 END) as total_pago,
        SUM(CASE WHEN status = 'pendente' THEN valor_total ELSE 0 END) as total_pendente,
        SUM(valor_total) as total_geral
    FROM mensalidades
    WHERE aluno_id = :aluno_id 
    AND ano_letivo_id = :ano_letivo_id
";
$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute([
    ':aluno_id' => $aluno_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$pagamentos = $stmt_pagamentos->fetch(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'aprovado':
            return '<span class="badge bg-success">Aprovado</span>';
        case 'recuperacao':
            return '<span class="badge bg-warning">Recuperação</span>';
        case 'reprovado':
            return '<span class="badge bg-danger">Reprovado</span>';
        default:
            return '<span class="badge bg-secondary">Pendente</span>';
    }
}

function getSituacaoGeral($notas) {
    $aprovado = true;
    $recuperacao = false;
    $reprovado = false;
    $total_disciplinas = 0;
    $disciplinas_negativas = [];
    
    foreach ($notas as $disciplina_id => $disciplina) {
        $nota_final = 0;
        foreach ([1,2,3] as $bimestre) {
            if (isset($disciplina['notas'][$bimestre]['media_final']) && $disciplina['notas'][$bimestre]['media_final'] > 0) {
                $nota_final = $disciplina['notas'][$bimestre]['media_final'];
            } elseif (isset($disciplina['notas'][$bimestre]['media_parcial']) && $disciplina['notas'][$bimestre]['media_parcial'] > 0) {
                $nota_final = $disciplina['notas'][$bimestre]['media_parcial'];
            }
        }
        
        if ($nota_final > 0) {
            $total_disciplinas++;
            if ($nota_final < 10) {
                $aprovado = false;
                $disciplinas_negativas[] = $disciplina['nome'];
                if ($nota_final >= 7) {
                    $recuperacao = true;
                } else {
                    $reprovado = true;
                }
            }
        }
    }
    
    if ($aprovado) return ['status' => 'aprovado', 'texto' => 'Aprovado', 'cor' => 'success', 'mensagem' => 'Aluno aprovado em todas as disciplinas'];
    if ($reprovado) return ['status' => 'reprovado', 'texto' => 'Reprovado', 'cor' => 'danger', 'mensagem' => 'Aluno reprovado por nota insuficiente'];
    if ($recuperacao) return ['status' => 'recuperacao', 'texto' => 'Recuperação', 'cor' => 'warning', 'mensagem' => 'Aluno em recuperação em: ' . implode(', ', $disciplinas_negativas)];
    return ['status' => 'pendente', 'texto' => 'Pendente', 'cor' => 'secondary', 'mensagem' => 'Aguardando lançamento de notas'];
}

$situacao_geral = getSituacaoGeral($notas_organizadas);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($aluno['nome']); ?> | Pedagógico | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        .page-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '👨‍🎓';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 120px;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 24px;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-editar {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 24px;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-editar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            color: white;
        }

        .profile-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
        }

        .profile-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 30px;
            text-align: center;
            border-bottom: 1px solid #e0e0e0;
        }

        .profile-img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            margin-bottom: 15px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .profile-img:hover {
            transform: scale(1.05);
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1A2A6C;
            margin-bottom: 5px;
        }

        .profile-meta {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .info-section {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
        }

        .info-title {
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-row {
            display: flex;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .info-label {
            width: 140px;
            font-weight: 600;
            color: #495057;
        }

        .info-value {
            flex: 1;
            color: #212529;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .table-notas {
            width: 100%;
            border-collapse: collapse;
        }

        .table-notas th {
            background: #f8f9fa;
            padding: 12px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
        }

        .table-notas td {
            padding: 10px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }

        .nota-positiva { color: #28a745; font-weight: bold; }
        .nota-negativa { color: #dc3545; font-weight: bold; }
        .nota-media { color: #ffc107; font-weight: bold; }

        .frequencia-card {
            text-align: center;
            padding: 20px;
        }

        .frequencia-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: bold;
            color: white;
        }

        @media (max-width: 768px) {
            .info-label {
                width: 120px;
                font-size: 0.8rem;
            }
            .info-value {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/menu_pedagogico.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-user-graduate me-2"></i> Ficha do Aluno</h2>
                    <p>Visualize todas as informações do aluno</p>
                    <small><i class="fas fa-calendar-alt me-1"></i> Ano Letivo: <?php echo $ano_letivo_ano; ?></small>
                </div>
                <div class="d-flex gap-2">
                    <a href="editar_aluno.php?id=<?php echo $aluno_id; ?>" class="btn-editar">
                        <i class="fas fa-edit"></i> Editar Aluno
                    </a>
                    <a href="listar_alunos.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>

        <!-- Perfil do Aluno -->
        <div class="profile-card">
            <div class="profile-header">
                <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                    <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="profile-img" onclick="ampliarImagem('<?php echo $aluno['foto']; ?>', '<?php echo htmlspecialchars($aluno['nome']); ?>')" style="cursor: pointer;">
                <?php else: ?>
                    <img src="../../assets/images/avatar-padrao.png" class="profile-img" onclick="ampliarImagem(null, '<?php echo htmlspecialchars($aluno['nome']); ?>')" style="cursor: pointer;">
                <?php endif; ?>
                <div class="profile-name"><?php echo htmlspecialchars($aluno['nome']); ?></div>
                <div class="profile-meta">
                    <i class="fas fa-id-card"></i> Matrícula: <?php echo htmlspecialchars($aluno['matricula']); ?> | 
                    <i class="fas fa-building"></i> <?php echo $aluno['turma_ano']; ?>ª - <?php echo htmlspecialchars($aluno['turma_nome']); ?> | 
                    <i class="fas fa-school"></i> <?php echo htmlspecialchars($aluno['escola_nome']); ?>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Coluna Esquerda - Informações Pessoais -->
            <div class="col-md-4">
                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-user-circle"></i> Dados Pessoais
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-user"></i> Nome:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['nome']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-id-card"></i> BI:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-calendar"></i> Data Nasc.:</div>
                        <div class="info-value"><?php echo $aluno['data_nascimento_formatada'] ?? '-'; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-birthday-cake"></i> Idade:</div>
                        <div class="info-value"><?php echo $aluno['idade']; ?> anos</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-venus-mars"></i> Género:</div>
                        <div class="info-value"><?php echo $aluno['genero'] == 'M' ? 'Masculino' : ($aluno['genero'] == 'F' ? 'Feminino' : '-'); ?></div>
                    </div>
                    <?php if (!empty($aluno['endereco'])): ?>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-map-marker-alt"></i> Endereço:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['endereco']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-phone-alt"></i> Contactos
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-envelope"></i> Email:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['email'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-phone"></i> Telefone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['telefone'] ?? '-'); ?></div>
                    </div>
                    <?php if (!empty($aluno['telefone_emergencia'])): ?>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-ambulance"></i> Emergência:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['telefone_emergencia']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-family"></i> Informações Familiares
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-male"></i> Nome do Pai:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['nome_pai'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-female"></i> Nome da Mãe:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['nome_mae'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-user-tie"></i> Encarregado:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['encarregado_nome'] ?? '-'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Coluna Direita - Informações Académicas -->
            <div class="col-md-8">
                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-graduation-cap"></i> Informações Académicas
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-building"></i> Turma:</div>
                        <div class="info-value"><?php echo $aluno['turma_ano']; ?>ª - <?php echo htmlspecialchars($aluno['turma_nome']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-clock"></i> Turno:</div>
                        <div class="info-value"><?php echo ucfirst($aluno['turno']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-door-open"></i> Sala:</div>
                        <div class="info-value"><?php echo $aluno['sala'] ?: 'Não definida'; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-id-card"></i> Nº Processo:</div>
                        <div class="info-value"><?php echo htmlspecialchars($aluno['numero_processo'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-calendar-alt"></i> Data Matrícula:</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($aluno['data_matricula'])); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-tasks"></i> Situação:</div>
                        <div class="info-value">
                            <span class="status-badge bg-<?php echo $situacao_geral['cor']; ?> bg-opacity-10 text-<?php echo $situacao_geral['cor']; ?> border border-<?php echo $situacao_geral['cor']; ?> border-opacity-25">
                                <?php echo $situacao_geral['texto']; ?>
                            </span>
                            <small class="text-muted ms-2"><?php echo $situacao_geral['mensagem']; ?></small>
                        </div>
                    </div>
                </div>

                <!-- Desempenho por Disciplina -->
                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-chart-line"></i> Desempenho por Disciplina
                    </div>
                    <div class="table-responsive">
                        <table class="table-notas">
                            <thead>
                                <tr>
                                    <th>Disciplina</th>
                                    <th>Professor</th>
                                    <th>1º Bim</th>
                                    <th>2º Bim</th>
                                    <th>3º Bim</th>
                                    <th>Média Final</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notas_organizadas as $disciplina_id => $disciplina): 
                                    $media_final = 0;
                                    $status_nota = '';
                                    for ($bim = 1; $bim <= 3; $bim++) {
                                        if (isset($disciplina['notas'][$bim]['media_final']) && $disciplina['notas'][$bim]['media_final'] > 0) {
                                            $media_final = $disciplina['notas'][$bim]['media_final'];
                                            $status_nota = $disciplina['notas'][$bim]['status'];
                                            break;
                                        } elseif (isset($disciplina['notas'][$bim]['media_parcial']) && $disciplina['notas'][$bim]['media_parcial'] > 0) {
                                            $media_final = $disciplina['notas'][$bim]['media_parcial'];
                                        }
                                    }
                                    
                                    $nota_class = '';
                                    if ($media_final >= 14) $nota_class = 'nota-positiva';
                                    elseif ($media_final >= 10) $nota_class = 'nota-media';
                                    elseif ($media_final > 0) $nota_class = 'nota-negativa';
                                ?>
                                <tr>
                                    <td class="text-start"><strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($disciplina['professor'] ?? '-'); ?></td>
                                    <td><?php echo isset($disciplina['notas'][1]['media_parcial']) ? number_format($disciplina['notas'][1]['media_parcial'], 1) : '-'; ?></td>
                                    <td><?php echo isset($disciplina['notas'][2]['media_parcial']) ? number_format($disciplina['notas'][2]['media_parcial'], 1) : '-'; ?></td>
                                    <td><?php echo isset($disciplina['notas'][3]['media_final']) ? number_format($disciplina['notas'][3]['media_final'], 1) : '-'; ?></td>
                                    <td class="<?php echo $nota_class; ?>"><strong><?php echo $media_final > 0 ? number_format($media_final, 1) : '-'; ?></strong></td>
                                    <td><?php echo getStatusBadge($status_nota); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Frequência Escolar -->
                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-calendar-check"></i> Frequência Escolar
                    </div>
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="frequencia-card">
                                <div class="frequencia-circle" style="background: linear-gradient(135deg, #28a745, #20c997);">
                                    <?php echo $frequencia['percentual_presenca'] ?? 0; ?>%
                                </div>
                                <h6>Percentual de Presença</h6>
                                <small class="text-muted">Total de aulas: <?php echo $frequencia['total_aulas'] ?? 0; ?></small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="frequencia-card">
                                <div class="frequencia-circle" style="background: linear-gradient(135deg, #28a745, #20c997);">
                                    <?php echo $frequencia['total_presencas'] ?? 0; ?>
                                </div>
                                <h6>Presenças</h6>
                                <small class="text-muted">Aulas assistidas</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="frequencia-card">
                                <div class="frequencia-circle" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                                    <?php echo $frequencia['total_faltas'] ?? 0; ?>
                                </div>
                                <h6>Faltas</h6>
                                <small class="text-muted">Aulas não assistidas</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="frequencia-card">
                                <div class="frequencia-circle" style="background: linear-gradient(135deg, #ffc107, #ff9800);">
                                    <?php echo $frequencia['total_atrasos'] ?? 0; ?>
                                </div>
                                <h6>Atrasos</h6>
                                <small class="text-muted">Chegou atrasado</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Detalhes das Faltas -->
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6 class="text-success"><i class="fas fa-check-circle"></i> Faltas Justificadas</h6>
                                    <h4><?php echo $frequencia['total_faltas_justificadas'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h6 class="text-warning"><i class="fas fa-clock"></i> Minutos de Atraso</h6>
                                    <h4><?php echo $frequencia['total_minutos_atraso'] ?? 0; ?> min</h4>
                                    <small>Média: <?php echo $frequencia['media_minutos_atraso'] ?? 0; ?> min</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Alerta de faltas consecutivas -->
                    <?php if (isset($faltas_consecutivas['faltas_consecutivas']) && $faltas_consecutivas['faltas_consecutivas'] >= 5): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Atenção!</strong> Este aluno tem <?php echo $faltas_consecutivas['faltas_consecutivas']; ?> faltas consecutivas 
                        (desde <?php echo date('d/m/Y', strtotime($faltas_consecutivas['inicio_faltas'])); ?>).
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Frequência por Bimestre -->
                <?php if (!empty($frequencia_bimestre)): ?>
                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-chart-bar"></i> Frequência por Bimestre
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Bimestre</th>
                                    <th>Presenças</th>
                                    <th>Faltas</th>
                                    <th>Faltas Justificadas</th>
                                    <th>Atrasos</th>
                                    <th>Total Aulas</th>
                                    <th>% Presença</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($frequencia_bimestre as $fb): ?>
                                <tr>
                                    <td class="text-center"><strong><?php echo $fb['bimestre']; ?>º</strong></td>
                                    <td class="text-center text-success"><?php echo $fb['presencas']; ?></td>
                                    <td class="text-center text-danger"><?php echo $fb['faltas']; ?></td>
                                    <td class="text-center text-warning"><?php echo $fb['faltas_justificadas']; ?></td>
                                    <td class="text-center text-info"><?php echo $fb['atrasos']; ?></td>
                                    <td class="text-center"><?php echo $fb['total_aulas']; ?></td>
                                    <td class="text-center">
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-<?php echo $fb['percentual'] >= 75 ? 'success' : ($fb['percentual'] >= 50 ? 'warning' : 'danger'); ?>" 
                                                 style="width: <?php echo $fb['percentual']; ?>%;">
                                                <?php echo $fb['percentual']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Financeiro -->
                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-coins"></i> Situação Financeira
                    </div>
                    <div class="row text-center">
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h5 class="text-success"><?php echo number_format($pagamentos['total_pago'] ?? 0, 2, ',', '.'); ?> Kz</h5>
                                    <small>Total Pago</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h5 class="text-danger"><?php echo number_format($pagamentos['total_pendente'] ?? 0, 2, ',', '.'); ?> Kz</h5>
                                    <small>Em Débito</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h5><?php echo number_format($pagamentos['total_geral'] ?? 0, 2, ',', '.'); ?> Kz</h5>
                                    <small>Total Geral</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Ampliar Imagem -->
    <div class="modal fade" id="modalImagem" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-image me-2"></i> Foto do Aluno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="imagemAmpliada" src="" alt="Foto do Aluno" style="max-width: 100%; max-height: 400px; border-radius: 10px;">
                    <p id="nomeAlunoImagem" class="mt-3 fw-bold"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function ampliarImagem(foto, nomeAluno) {
            let imagemUrl = '';
            if (foto) {
                imagemUrl = '../../uploads/alunos/fotos/' + foto;
            } else {
                imagemUrl = '../../assets/images/avatar-padrao.png';
            }
            document.getElementById('imagemAmpliada').src = imagemUrl;
            document.getElementById('nomeAlunoImagem').innerHTML = '<i class="fas fa-user me-2"></i>' + nomeAluno;
            new bootstrap.Modal(document.getElementById('modalImagem')).show();
        }
    </script>
</body>
</html>