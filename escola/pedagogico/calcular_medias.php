<?php
// escola/pedagogico/calcular_medias.php - Calcular Médias Finais

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

// Buscar turmas da escola
$sql_turmas = "
    SELECT 
        t.id,
        t.nome,
        t.ano,
        tr.nome as turno_nome,
        t.ano_letivo_id,
        al.ano as ano_letivo_ano
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas_lista = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Obter parâmetros de filtro
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;

// Se não tem turma selecionada e tem turmas disponíveis, pegar a primeira
if ($turma_id == 0 && !empty($turmas_lista)) {
    $turma_id = $turmas_lista[0]['id'];
    $ano_letivo_id = $turmas_lista[0]['ano_letivo_id'];
}

// Buscar dados da turma
$turma = null;
if ($turma_id > 0) {
    $sql_turma = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE t.id = :turma_id
    ";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':turma_id' => $turma_id]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if ($turma && $ano_letivo_id == 0) {
        $ano_letivo_id = $turma['ano_letivo_id'];
    }
}

// Buscar disciplinas da turma
$disciplinas = [];
if ($turma_id > 0) {
    $sql_disciplinas = "
        SELECT 
            d.id,
            d.nome,
            d.codigo,
            d.carga_horaria,
            d.cor
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar alunos da turma
$alunos = [];
if ($turma_id > 0) {
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome ASC
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar anos letivos
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Buscar todas as notas dos alunos
$notas_por_aluno_disciplina = [];
if ($turma_id > 0 && $ano_letivo_id > 0) {
    $sql_notas = "
        SELECT 
            n.estudante_id,
            n.disciplina_id,
            n.bimestre,
            n.mac,
            n.npt,
            n.exame_normal,
            n.exame_recurso,
            n.exame_especial,
            n.media_parcial,
            n.media_final,
            n.status
        FROM notas n
        WHERE n.turma_id = :turma_id 
        AND n.ano_letivo_id = :ano_letivo_id
    ";
    $params = [
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ];
    
    if ($disciplina_id > 0) {
        $sql_notas .= " AND n.disciplina_id = :disciplina_id";
        $params[':disciplina_id'] = $disciplina_id;
    }
    
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute($params);
    $notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar notas por aluno e disciplina
    foreach ($notas as $nota) {
        $estudante_id = $nota['estudante_id'];
        $disciplina = $nota['disciplina_id'];
        $bimestre = $nota['bimestre'];
        
        if (!isset($notas_por_aluno_disciplina[$estudante_id])) {
            $notas_por_aluno_disciplina[$estudante_id] = [];
        }
        if (!isset($notas_por_aluno_disciplina[$estudante_id][$disciplina])) {
            $notas_por_aluno_disciplina[$estudante_id][$disciplina] = [
                'notas' => [],
                'media_final' => null
            ];
        }
        
        $notas_por_aluno_disciplina[$estudante_id][$disciplina]['notas'][$bimestre] = [
            'mac' => $nota['mac'],
            'npt' => $nota['npt'],
            'exame_normal' => $nota['exame_normal'],
            'exame_recurso' => $nota['exame_recurso'],
            'exame_especial' => $nota['exame_especial'],
            'media_parcial' => $nota['media_parcial'],
            'media_final' => $nota['media_final'],
            'status' => $nota['status']
        ];
        
        // Se já tem média final salva, usar
        if ($nota['media_final'] !== null) {
            $notas_por_aluno_disciplina[$estudante_id][$disciplina]['media_final'] = $nota['media_final'];
        }
    }
}

// Função para calcular média final do aluno na disciplina
function calcularMediaFinalDisciplina($notas_bimestres) {
    $medias_bimestres = [];
    
    for ($bim = 1; $bim <= 4; $bim++) {
        if (isset($notas_bimestres[$bim])) {
            $nota = $notas_bimestres[$bim];
            
            // Determinar a nota do bimestre (prioridade: exame > media_parcial)
            if ($nota['exame_normal'] !== null && $nota['exame_normal'] > 0) {
                $media_bimestre = $nota['exame_normal'];
            } elseif ($nota['exame_recurso'] !== null && $nota['exame_recurso'] > 0) {
                $media_bimestre = $nota['exame_recurso'];
            } elseif ($nota['exame_especial'] !== null && $nota['exame_especial'] > 0) {
                $media_bimestre = $nota['exame_especial'];
            } elseif ($nota['media_parcial'] !== null && $nota['media_parcial'] > 0) {
                $media_bimestre = $nota['media_parcial'];
            } else {
                $media_bimestre = null;
            }
            
            if ($media_bimestre !== null) {
                $medias_bimestres[] = $media_bimestre;
            }
        }
    }
    
    // Calcular média final da disciplina (média dos bimestres)
    if (count($medias_bimestres) > 0) {
        return round(array_sum($medias_bimestres) / count($medias_bimestres), 1);
    }
    
    return null;
}

// Processar cálculo de médias
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'calcular') {
    $disciplina_id_calc = (int)$_POST['disciplina_id'];
    $turma_id_calc = (int)$_POST['turma_id'];
    $ano_letivo_id_calc = (int)$_POST['ano_letivo_id'];
    
    $calculados = 0;
    $erros = 0;
    
    try {
        $conn->beginTransaction();
        
        // Buscar todas as notas da turma/disciplina
        $sql_busca = "
            SELECT 
                id, estudante_id, disciplina_id, bimestre,
                mac, npt, exame_normal, exame_recurso, exame_especial,
                media_parcial, media_final,status
            FROM notas
            WHERE turma_id = :turma_id 
            AND disciplina_id = :disciplina_id
            AND ano_letivo_id = :ano_letivo_id
        ";
        $stmt_busca = $conn->prepare($sql_busca);
        $stmt_busca->execute([
            ':turma_id' => $turma_id_calc,
            ':disciplina_id' => $disciplina_id_calc,
            ':ano_letivo_id' => $ano_letivo_id_calc
        ]);
        $todas_notas = $stmt_busca->fetchAll(PDO::FETCH_ASSOC);
        
        // Organizar por aluno
        $notas_por_aluno = [];
        foreach ($todas_notas as $nota) {
            $aluno_id = $nota['estudante_id'];
            $bimestre = $nota['bimestre'];
            
            if (!isset($notas_por_aluno[$aluno_id])) {
                $notas_por_aluno[$aluno_id] = [];
            }
            
            $notas_por_aluno[$aluno_id][$bimestre] = $nota;
        }
        
        // Calcular média final para cada aluno
        foreach ($notas_por_aluno as $aluno_id => $notas_bimestres) {
            $medias_bimestres = [];
            
            for ($bim = 1; $bim <= 4; $bim++) {
                if (isset($notas_bimestres[$bim])) {
                    $nota = $notas_bimestres[$bim];
                    
                    // Determinar a nota do bimestre
                    if ($nota['exame_normal'] !== null && $nota['exame_normal'] > 0) {
                        $media_bimestre = $nota['exame_normal'];
                    } elseif ($nota['exame_recurso'] !== null && $nota['exame_recurso'] > 0) {
                        $media_bimestre = $nota['exame_recurso'];
                    } elseif ($nota['exame_especial'] !== null && $nota['exame_especial'] > 0) {
                        $media_bimestre = $nota['exame_especial'];
                    } elseif ($nota['media_parcial'] !== null && $nota['media_parcial'] > 0) {
                        $media_bimestre = $nota['media_parcial'];
                    } else {
                        $media_bimestre = null;
                    }
                    
                    if ($media_bimestre !== null && $media_bimestre > 0) {
                        $medias_bimestres[] = $media_bimestre;
                    }
                }
            }
            
            // Calcular média final da disciplina
            $media_final = null;
            if (count($medias_bimestres) > 0) {
                $media_final = round(array_sum($medias_bimestres) / count($medias_bimestres), 1);
            }
            
            // Determinar status
            $status = 'pendente';
            if ($media_final !== null) {
                if ($media_final >= 10) {
                    $status = 'aprovado';
                } elseif ($media_final >= 7) {
                    $status = 'recuperacao';
                } else {
                    $status = 'reprovado';
                }
            }
            
            // Atualizar cada nota do aluno com a média final
            foreach ($notas_bimestres as $bimestre => $nota) {
                $sql_update = "
                    UPDATE notas 
                    SET media_final = :media_final, 
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :id
                ";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([
                    ':media_final' => $media_final,
                    ':status' => $status,
                    ':id' => $nota['id']
                ]);
                $calculados++;
            }
        }
        
        $conn->commit();
        $mensagem = "✅ Médias calculadas com sucesso! ($calculados registros atualizados)";
        
        // Recarregar dados
        $stmt_notas = $conn->prepare($sql_busca);
        $stmt_notas->execute([
            ':turma_id' => $turma_id_calc,
            ':disciplina_id' => $disciplina_id_calc,
            ':ano_letivo_id' => $ano_letivo_id_calc
        ]);
        $notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
        
        // Reorganizar notas
        $notas_por_aluno_disciplina = [];
        foreach ($notas as $nota) {
            $estudante_id = $nota['estudante_id'];
            $disciplina = $nota['disciplina_id'];
            $bimestre = $nota['bimestre'];
            
            if (!isset($notas_por_aluno_disciplina[$estudante_id])) {
                $notas_por_aluno_disciplina[$estudante_id] = [];
            }
            if (!isset($notas_por_aluno_disciplina[$estudante_id][$disciplina])) {
                $notas_por_aluno_disciplina[$estudante_id][$disciplina] = [
                    'notas' => [],
                    'media_final' => null
                ];
            }
            
            $notas_por_aluno_disciplina[$estudante_id][$disciplina]['notas'][$bimestre] = [
                'mac' => $nota['mac'],
                'npt' => $nota['npt'],
                'exame_normal' => $nota['exame_normal'],
                'exame_recurso' => $nota['exame_recurso'],
                'exame_especial' => $nota['exame_especial'],
                'media_parcial' => $nota['media_parcial'],
                'media_final' => $nota['media_final'],
                'status' => $nota['status']
            ];
            
            if ($nota['media_final'] !== null) {
                $notas_por_aluno_disciplina[$estudante_id][$disciplina]['media_final'] = $nota['media_final'];
            }
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $erro = "Erro ao calcular médias: " . $e->getMessage();
    }
}

// Calcular estatísticas
$total_alunos = count($alunos);
$total_disciplinas = $disciplina_id > 0 ? 1 : count($disciplinas);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calcular Médias - SIGE Angola</title>
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
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filtro-group {
            flex: 1;
            min-width: 180px;
        }
        
        .filtro-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .filtro-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            background: white;
        }
        
        .btn-filtrar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 25px;
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
        
        .info-bar {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .info-grid {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: space-around;
        }
        
        .info-item {
            text-align: center;
        }
        
        .info-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: bold;
            color: #1e5799;
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
            padding: 20px;
            overflow-x: auto;
        }
        
        .btn-calcular {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            padding: 10px 20px;
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
        
        .btn-calcular:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .table-medias {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-medias th {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 12px;
        }
        
        .table-medias td {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-medias tr:hover {
            background: #f8f9fa;
        }
        
        .aluno-nome {
            text-align: left;
            font-weight: 600;
        }
        
        .nota-cell {
            font-weight: bold;
            font-size: 14px;
        }
        
        .nota-aprovado {
            color: #27ae60;
            background: #d5f4e6;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .nota-recuperacao {
            color: #f39c12;
            background: #fef9e7;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .nota-reprovado {
            color: #c0392b;
            background: #fadbd8;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .nota-pendente {
            color: #7f8c8d;
            background: #ecf0f1;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .bimestre-col {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        
        .alert-danger {
            background: #fadbd8;
            color: #c0392b;
            border-left: 4px solid #c0392b;
        }
        
        .alert-info {
            background: #d4e6f1;
            color: #1e5799;
            border-left: 4px solid #1e5799;
        }
        
        .alert-warning {
            background: #fef9e7;
            color: #f39c12;
            border-left: 4px solid #f39c12;
        }
        
        .disciplina-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
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
            
            .table-medias {
                font-size: 11px;
            }
            
            .table-medias th, .table-medias td {
                padding: 6px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📊 Calcular Médias Finais</h1>
            <p>Calcule as médias finais dos alunos por disciplina</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success"><?php echo $mensagem; ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger"><?php echo $erro; ?></div>
    <?php endif; ?>
    
    <!-- Filtros -->
    <div class="filtros-card">
        <div class="filtros-header">
            🔍 Filtrar
        </div>
        <div class="filtros-body">
            <form method="GET" action="" id="formFiltros">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Turma</label>
                        <select name="turma_id" class="filtro-select" required>
                            <option value="">Selecione uma turma</option>
                            <?php foreach ($turmas_lista as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo ($turma_id == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['nome']); ?> - <?php echo $t['ano']; ?>ª - <?php echo ucfirst($t['turno_nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Disciplina</label>
                        <select name="disciplina_id" class="filtro-select">
                            <option value="0">Todas as disciplinas</option>
                            <?php foreach ($disciplinas as $disc): ?>
                                <option value="<?php echo $disc['id']; ?>" <?php echo ($disciplina_id == $disc['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($disc['nome']); ?> (<?php echo htmlspecialchars($disc['codigo']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Ano Letivo</label>
                        <select name="ano_letivo_id" class="filtro-select">
                            <option value="">Todos os anos</option>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($turma && $ano_letivo_id > 0): ?>
        <!-- Informações -->
        <div class="info-bar">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">📚 Turma</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🎓 Ano</div>
                    <div class="info-value"><?php echo $turma['ano']; ?>ª Classe</div>
                </div>
                <div class="info-item">
                    <div class="info-label">🕐 Turno</div>
                    <div class="info-value"><?php echo ucfirst($turma['turno_nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Ano Letivo</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['ano_letivo_ano']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">👨‍🎓 Alunos</div>
                    <div class="info-value"><?php echo $total_alunos; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📖 Disciplinas</div>
                    <div class="info-value"><?php echo $total_disciplinas; ?></div>
                </div>
            </div>
        </div>
        
        <?php
        // Se tem disciplina específica, mostrar apenas ela
        $disciplinas_para_mostrar = [];
        if ($disciplina_id > 0) {
            foreach ($disciplinas as $d) {
                if ($d['id'] == $disciplina_id) {
                    $disciplinas_para_mostrar[] = $d;
                    break;
                }
            }
        } else {
            $disciplinas_para_mostrar = $disciplinas;
        }
        ?>
        
        <?php foreach ($disciplinas_para_mostrar as $disc): ?>
            <div class="card">
                <div class="card-header">
                    <span>
                        📖 <?php echo htmlspecialchars($disc['nome']); ?>
                        <span class="disciplina-badge" style="background: <?php echo $disc['cor'] ?? '#1e5799'; ?>20; color: <?php echo $disc['cor'] ?? '#1e5799'; ?>; border: 1px solid <?php echo $disc['cor'] ?? '#1e5799'; ?>;">
                            <?php echo htmlspecialchars($disc['codigo']); ?>
                        </span>
                    </span>
                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja calcular as médias finais para esta disciplina?')">
                        <input type="hidden" name="action" value="calcular">
                        <input type="hidden" name="disciplina_id" value="<?php echo $disc['id']; ?>">
                        <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                        <input type="hidden" name="ano_letivo_id" value="<?php echo $ano_letivo_id; ?>">
                        <button type="submit" class="btn-calcular">
                            🧮 Calcular Médias
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <table class="table-medias">
                        <thead>
                            <tr>
                                <th width="25%">Aluno</th>
                                <th width="10%">Matrícula</th>
                                <th width="10%">1º Bim</th>
                                <th width="10%">2º Bim</th>
                                <th width="10%">3º Bim</th>
                                <th width="10%">4º Bim</th>
                                <th width="10%">Média Final</th>
                                <th width="10%">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $aluno):
                                $notas_disciplina = isset($notas_por_aluno_disciplina[$aluno['id']][$disc['id']]) 
                                    ? $notas_por_aluno_disciplina[$aluno['id']][$disc['id']] 
                                    : null;
                                
                                $medias_bimestres = [];
                                for ($bim = 1; $bim <= 4; $bim++) {
                                    if ($notas_disciplina && isset($notas_disciplina['notas'][$bim])) {
                                        $nota = $notas_disciplina['notas'][$bim];
                                        // Determinar nota do bimestre
                                        if ($nota['exame_normal'] !== null && $nota['exame_normal'] > 0) {
                                            $media_bim = $nota['exame_normal'];
                                        } elseif ($nota['exame_recurso'] !== null && $nota['exame_recurso'] > 0) {
                                            $media_bim = $nota['exame_recurso'];
                                        } elseif ($nota['exame_especial'] !== null && $nota['exame_especial'] > 0) {
                                            $media_bim = $nota['exame_especial'];
                                        } elseif ($nota['media_parcial'] !== null && $nota['media_parcial'] > 0) {
                                            $media_bim = $nota['media_parcial'];
                                        } else {
                                            $media_bim = null;
                                        }
                                        $medias_bimestres[$bim] = $media_bim;
                                    } else {
                                        $medias_bimestres[$bim] = null;
                                    }
                                }
                                
                                $media_final = $notas_disciplina ? $notas_disciplina['media_final'] : null;
                                
                                // Determinar classe da nota
                                if ($media_final !== null) {
                                    if ($media_final >= 10) {
                                        $nota_class = 'nota-aprovado';
                                        $status_text = '✅ Aprovado';
                                    } elseif ($media_final >= 7) {
                                        $nota_class = 'nota-recuperacao';
                                        $status_text = '⚠️ Recuperação';
                                    } else {
                                        $nota_class = 'nota-reprovado';
                                        $status_text = '❌ Reprovado';
                                    }
                                } else {
                                    $nota_class = 'nota-pendente';
                                    $status_text = '⏳ Pendente';
                                }
                            ?>
                                <tr>
                                    <td class="aluno-nome"><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    <td class="bimestre-col"><?php echo $medias_bimestres[1] !== null ? number_format($medias_bimestres[1], 1) : '-'; ?></td>
                                    <td class="bimestre-col"><?php echo $medias_bimestres[2] !== null ? number_format($medias_bimestres[2], 1) : '-'; ?></td>
                                    <td class="bimestre-col"><?php echo $medias_bimestres[3] !== null ? number_format($medias_bimestres[3], 1) : '-'; ?></td>
                                    <td class="bimestre-col"><?php echo $medias_bimestres[4] !== null ? number_format($medias_bimestres[4], 1) : '-'; ?></td>
                                    <td class="nota-cell">
                                        <span class="<?php echo $nota_class; ?>">
                                            <?php echo $media_final !== null ? number_format($media_final, 1) : '-'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo $nota_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($alunos)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px;">
                                        Nenhum aluno matriculado nesta turma.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($disciplinas_para_mostrar)): ?>
            <div class="alert alert-info">
                ℹ️ Nenhuma disciplina encontrada para esta turma.
                <a href="atribuir_disciplinas.php?turma_id=<?php echo $turma_id; ?>">Clique aqui para atribuir disciplinas</a>.
            </div>
        <?php endif; ?>
        
    <?php elseif ($turma_id > 0 && !$turma): ?>
        <div class="alert alert-danger">
            ❌ Turma não encontrada ou não pertence à sua escola.
        </div>
    <?php elseif (empty($turmas_lista)): ?>
        <div class="alert alert-info">
            ℹ️ Nenhuma turma cadastrada. <a href="cadastrar_turma.php">Clique aqui para cadastrar uma turma</a>.
        </div>
    <?php endif; ?>
    
    <!-- Informações Adicionais -->
    <div class="card">
        <div class="card-header">
            ℹ️ Informações Importantes
        </div>
        <div class="card-body">
            <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                <li><strong>📊 Cálculo da Média Final:</strong> Média aritmética das notas dos 4 bimestres.</li>
                <li><strong>📝 Prioridade das Notas:</strong> Exame Normal > Exame Recurso > Exame Especial > Média Parcial (MAC+NPT)/2.</li>
                <li><strong>✅ Critério de Aprovação:</strong> Média final ≥ 10 → Aprovado; Média 7-9 → Recuperação; Média < 7 → Reprovado.</li>
                <li><strong>🎯 3º Bimestre:</strong> As notas são lançadas na escala 0-10 e convertidas automaticamente para 0-20.</li>
                <li><strong>⚠️ Atenção:</strong> O cálculo das médias deve ser feito após o lançamento de todas as notas dos bimestres.</li>
                <li><strong>📌 Dica:</strong> Utilize o filtro por disciplina para visualizar apenas uma disciplina específica.</li>
            </ul>
        </div>
    </div>
</div>
</body>
</html>