<?php
// escola/pedagogico/pauta_final.php - Pauta Final por Turma e Ano

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

// Buscar anos letivos
$sql_anos = "SELECT id, ano, ativo FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

$ano_selecionado = isset($_GET['ano']) ? (int)$_GET['ano'] : (date('Y'));
$turma_selecionada = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

// Buscar turmas do ano selecionado
$sql_turmas = "
    SELECT 
        t.id, 
        t.nome as turma_nome,
        c.nome as classe_nome,
        c.ordem,
        (SELECT COUNT(*) FROM matriculas m 
         WHERE m.turma_id = t.id 
         AND m.ano_letivo = (SELECT id FROM ano_letivo WHERE ano = :ano AND escola_id = :escola_id LIMIT 1)
         AND m.status = 'ativa') as total_alunos,
        (SELECT COUNT(DISTINCT pdt.professor_id) FROM professor_disciplina_turma pdt WHERE pdt.turma_id = t.id) as total_professores,
        (SELECT COUNT(DISTINCT pdt.disciplina_id) FROM professor_disciplina_turma pdt WHERE pdt.turma_id = t.id) as total_disciplinas
    FROM turmas t
    INNER JOIN classes c ON c.id = t.classe_id
    WHERE t.escola_id = :escola_id1 AND t.status = 'ativa'
    ORDER BY c.ordem, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([
    ':ano' => $ano_selecionado,
    ':escola_id' => $escola_id,
    ':escola_id1' => $escola_id
]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar dados detalhados da turma selecionada
$turma_detalhes = null;
$disciplinas = [];
$alunos = [];
$estatisticas = [];

if ($turma_selecionada) {
    // Buscar informações da turma
    $sql_turma_info = "
        SELECT t.*, c.nome as classe_nome, c.ordem, e.nome as escola_nome
        FROM turmas t
        INNER JOIN classes c ON c.id = t.classe_id
        INNER JOIN escolas e ON e.id = t.escola_id
        WHERE t.id = :turma_id
    ";
    $stmt_info = $conn->prepare($sql_turma_info);
    $stmt_info->execute([':turma_id' => $turma_selecionada]);
    $turma_detalhes = $stmt_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar disciplinas da turma através da tabela professor_disciplina_turma
    $sql_disciplinas = "
        SELECT DISTINCT d.id, d.nome, d.codigo, d.carga_horaria
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
    
    $soma_notas_turma = 0;
    $total_notas = 0;
    $aprovados = 0;
    $recuperacao = 0;
    $reprovados = 0;
    
    foreach ($alunos as $key => $aluno) {
        // Buscar notas do aluno
        $sql_notas = "
            SELECT disciplina_id, 
                   mac, 
                   npt, 
                   exame_normal, 
                   exame_recurso, 
                   exame_especial, 
                   exame_oral, 
                   exame_escrito, 
                   media_parcial, 
                   media_final,
                   status
            FROM notas 
            WHERE estudante_id = :aluno_id 
            AND turma_id = :turma_id
            AND ano_letivo_id = (SELECT id FROM ano_letivo WHERE ano = :ano AND escola_id = :escola_id LIMIT 1)
        ";
        $stmt_notas = $conn->prepare($sql_notas);
        $stmt_notas->execute([
            ':aluno_id' => $aluno['id'],
            ':turma_id' => $turma_selecionada,
            ':ano' => $ano_selecionado,
            ':escola_id' => $escola_id
        ]);
        $notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
        
        $notas_array = [];
        $soma_notas_aluno = 0;
        $count_notas_aluno = 0;
        
        foreach ($notas as $nota) {
            // Usar a média final já calculada ou calcular a partir dos componentes
            if ($nota['media_final'] !== null && $nota['media_final'] > 0) {
                $nota_final = (float)$nota['media_final'];
            } else {
                // Calcular média a partir dos componentes
                $nota_final = 0;
                $componentes = 0;
                
                if ($nota['mac'] !== null) {
                    $nota_final += (float)$nota['mac'];
                    $componentes++;
                }
                if ($nota['npt'] !== null) {
                    $nota_final += (float)$nota['npt'];
                    $componentes++;
                }
                if ($nota['exame_normal'] !== null) {
                    $nota_final += (float)$nota['exame_normal'];
                    $componentes++;
                }
                if ($nota['exame_recurso'] !== null) {
                    $nota_final += (float)$nota['exame_recurso'];
                    $componentes++;
                }
                if ($nota['exame_especial'] !== null) {
                    $nota_final += (float)$nota['exame_especial'];
                    $componentes++;
                }
                if ($nota['exame_oral'] !== null) {
                    $nota_final += (float)$nota['exame_oral'];
                    $componentes++;
                }
                if ($nota['exame_escrito'] !== null) {
                    $nota_final += (float)$nota['exame_escrito'];
                    $componentes++;
                }
                
                if ($componentes > 0) {
                    $nota_final = round($nota_final / $componentes, 1);
                }
            }
            
            $notas_array[$nota['disciplina_id']] = $nota_final;
            $soma_notas_aluno += $nota_final;
            $count_notas_aluno++;
            $soma_notas_turma += $nota_final;
            $total_notas++;
        }
        
        $media = $count_notas_aluno > 0 ? round($soma_notas_aluno / $count_notas_aluno, 1) : 0;
        
        if ($media >= 10) {
            $resultado = 'Aprovado';
            $aprovados++;
        } elseif ($media >= 7) {
            $resultado = 'Recuperação';
            $recuperacao++;
        } else {
            $resultado = 'Reprovado';
            $reprovados++;
        }
        
        $alunos[$key]['notas'] = $notas_array;
        $alunos[$key]['media_final'] = $media;
        $alunos[$key]['resultado_final'] = $resultado;
    }
    
    // Calcular estatísticas da turma
    $total_alunos = count($alunos);
    $media_turma = $total_notas > 0 ? round($soma_notas_turma / $total_notas, 1) : 0;
    $taxa_aprovacao = $total_alunos > 0 ? round(($aprovados / $total_alunos) * 100, 1) : 0;
    $taxa_recuperacao = $total_alunos > 0 ? round(($recuperacao / $total_alunos) * 100, 1) : 0;
    $taxa_reprovacao = $total_alunos > 0 ? round(($reprovados / $total_alunos) * 100, 1) : 0;
    
    $estatisticas = [
        'total_alunos' => $total_alunos,
        'aprovados' => $aprovados,
        'recuperacao' => $recuperacao,
        'reprovados' => $reprovados,
        'media_turma' => $media_turma,
        'taxa_aprovacao' => $taxa_aprovacao,
        'taxa_recuperacao' => $taxa_recuperacao,
        'taxa_reprovacao' => $taxa_reprovacao
    ];
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pauta Final - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); padding: 20px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        .header {
            background: linear-gradient(135deg, #1e5799 0%, #2c3e50 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 5px; font-weight: 700; }
        .header p { opacity: 0.9; font-size: 14px; }
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 24px;
            border-radius: 40px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            font-weight: 600;
        }
        .btn-voltar:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); color: white; }
        
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s;
        }
        .filter-card:hover { transform: translateY(-2px); }
        
        .turmas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            margin-top: 25px;
        }
        
        .turma-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            display: block;
        }
        .turma-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        .turma-card.active {
            border: 2px solid #1e5799;
            box-shadow: 0 10px 30px rgba(30,87,153,0.2);
        }
        
        .turma-card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px;
            position: relative;
        }
        .turma-card-header h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
        }
        .turma-card-header p {
            margin: 5px 0 0;
            opacity: 0.8;
            font-size: 0.85rem;
        }
        
        .turma-card-body {
            padding: 20px;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .stat-item:last-child { border-bottom: none; }
        .stat-label { color: #6c757d; font-size: 0.85rem; }
        .stat-value { font-weight: 700; font-size: 1rem; color: #2c3e50; }
        
        .badge-count {
            position: absolute;
            top: 15px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        .turma-detalhes {
            background: white;
            border-radius: 20px;
            margin-top: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .detalhes-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-export-pdf {
            background: #dc3545;
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }
        .btn-export-pdf:hover { background: #c82333; transform: translateY(-2px); color: white; }
        
        .btn-export-excel {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }
        .btn-export-excel:hover { background: #1e7e34; transform: translateY(-2px); color: white; }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 25px;
            background: #f8f9fa;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stat-card h4 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .stat-card p { color: #6c757d; margin: 0; font-size: 12px; text-transform: uppercase; }
        
        .progress-bar-custom {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.5s; }
        
        .table-pauta { width: 100%; border-collapse: collapse; }
        .table-pauta th {
            background: #1e5799;
            color: white;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            font-size: 12px;
            position: sticky;
            top: 0;
        }
        .table-pauta td {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
            text-align: center;
            vertical-align: middle;
        }
        .table-pauta tr:hover { background: #f8f9fa; }
        
        .badge-aprovado { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px; }
        .badge-reprovado { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px; }
        .badge-recuperacao { background: #ffc107; color: #856404; padding: 4px 12px; border-radius: 20px; font-size: 11px; }
        
        .table-responsive { overflow-x: auto; max-height: 500px; overflow-y: auto; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .turmas-grid { grid-template-columns: 1fr; }
            .stats-cards { grid-template-columns: repeat(2, 1fr); }
            .export-buttons { flex-direction: column; width: 100%; }
            .btn-export-pdf, .btn-export-excel { justify-content: center; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-file-alt"></i> Pauta Final</h1>
            <p>Selecione o ano letivo e a turma para visualizar a pauta final</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <!-- Filtro de Ano Letivo -->
    <div class="filter-card">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold mb-2">
                    <i class="fas fa-calendar-alt text-primary"></i> Ano Letivo
                </label>
                <select name="ano" class="form-select form-select-lg" required onchange="this.form.submit()">
                    <option value="">Selecione o ano letivo</option>
                    <?php foreach ($anos_letivos as $ano): ?>
                        <option value="<?php echo $ano['ano']; ?>" <?php echo $ano_selecionado == $ano['ano'] ? 'selected' : ''; ?>>
                            <?php echo $ano['ano']; ?> <?php echo $ano['ativo'] ? '✓ (Ativo)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8 d-flex align-items-end">
                <div class="text-muted">
                    <i class="fas fa-info-circle"></i> 
                    <?php if ($ano_selecionado): ?>
                        Mostrando turmas do ano letivo <strong><?php echo $ano_selecionado; ?></strong>
                    <?php else: ?>
                        Selecione um ano letivo para visualizar as turmas
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Lista de Turmas -->
    <?php if ($ano_selecionado): ?>
        <div class="turmas-grid">
            <?php if (empty($turmas)): ?>
                <div class="empty-state">
                    <i class="fas fa-chalkboard fa-3x text-muted mb-3"></i>
                    <h5>Nenhuma turma encontrada</h5>
                    <p class="text-muted">Não há turmas ativas para o ano letivo de <?php echo $ano_selecionado; ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($turmas as $turma): ?>
                    <a href="?ano=<?php echo $ano_selecionado; ?>&turma_id=<?php echo $turma['id']; ?>" class="turma-card <?php echo $turma_selecionada == $turma['id'] ? 'active' : ''; ?>">
                        <div class="turma-card-header">
                            <span class="badge-count">
                                <i class="fas fa-users"></i> <?php echo $turma['total_alunos']; ?> alunos
                            </span>
                            <h3><i class="fas fa-building"></i> <?php echo htmlspecialchars($turma['classe_nome']); ?></h3>
                            <p><?php echo htmlspecialchars($turma['turma_nome']); ?></p>
                        </div>
                        <div class="turma-card-body">
                            <div class="stat-item">
                                <span class="stat-label"><i class="fas fa-user-graduate"></i> Total de Alunos</span>
                                <span class="stat-value"><?php echo $turma['total_alunos']; ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label"><i class="fas fa-chalkboard-user"></i> Professores</span>
                                <span class="stat-value"><?php echo $turma['total_professores']; ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label"><i class="fas fa-book"></i> Disciplinas</span>
                                <span class="stat-value"><?php echo $turma['total_disciplinas']; ?></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Detalhes da Turma Selecionada -->
    <?php if ($turma_selecionada && $turma_detalhes && !empty($alunos)): ?>
        <div class="turma-detalhes">
            <div class="detalhes-header">
                <div>
                    <h3 class="mb-1"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($turma_detalhes['classe_nome']); ?> - <?php echo htmlspecialchars($turma_detalhes['nome']); ?></h3>
                    <p class="mb-0 opacity-75">Ano Letivo: <?php echo $ano_selecionado; ?> | Total de Alunos: <?php echo $estatisticas['total_alunos']; ?></p>
                </div>
                <div class="export-buttons">
                    <a href="exportar_pauta_pdf.php?ano=<?php echo $ano_selecionado; ?>&turma_id=<?php echo $turma_selecionada; ?>" target="_blank" class="btn-export-pdf">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </a>
                    <a href="exportar_pauta_excel.php?ano=<?php echo $ano_selecionado; ?>&turma_id=<?php echo $turma_selecionada; ?>" class="btn-export-excel">
                        <i class="fas fa-file-excel"></i> Exportar Excel
                    </a>
                </div>
            </div>
            
            <!-- Cards de Estatísticas da Turma -->
            <div class="stats-cards">
                <div class="stat-card">
                    <h4 class="text-primary"><?php echo $estatisticas['media_turma']; ?></h4>
                    <p>Média da Turma</p>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: <?php echo ($estatisticas['media_turma'] / 20) * 100; ?>%; background: #1e5799;"></div>
                    </div>
                </div>
                <div class="stat-card">
                    <h4 class="text-success"><?php echo $estatisticas['aprovados']; ?></h4>
                    <p>Aprovados</p>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: <?php echo $estatisticas['taxa_aprovacao']; ?>%; background: #28a745;"></div>
                    </div>
                    <small><?php echo $estatisticas['taxa_aprovacao']; ?>%</small>
                </div>
                <div class="stat-card">
                    <h4 class="text-warning"><?php echo $estatisticas['recuperacao']; ?></h4>
                    <p>Recuperação</p>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: <?php echo $estatisticas['taxa_recuperacao']; ?>%; background: #ffc107;"></div>
                    </div>
                    <small><?php echo $estatisticas['taxa_recuperacao']; ?>%</small>
                </div>
                <div class="stat-card">
                    <h4 class="text-danger"><?php echo $estatisticas['reprovados']; ?></h4>
                    <p>Reprovados</p>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: <?php echo $estatisticas['taxa_reprovacao']; ?>%; background: #dc3545;"></div>
                    </div>
                    <small><?php echo $estatisticas['taxa_reprovacao']; ?>%</small>
                </div>
            </div>
            
            <!-- Tabela da Pauta -->
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table-pauta">
                        <thead>
                            <tr>
                                <th>Nº</th>
                                <th>Matrícula</th>
                                <th>Nome do Aluno</th>
                                <?php foreach ($disciplinas as $disc): ?>
                                    <th title="<?php echo htmlspecialchars($disc['nome']); ?>">
                                        <?php echo htmlspecialchars($disc['codigo'] ?: substr($disc['nome'], 0, 3)); ?>
                                    </th>
                                <?php endforeach; ?>
                                <th>Média</th>
                                <th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($alunos as $aluno): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td><?php echo htmlspecialchars($aluno['matricula'] ?: '-'); ?></td>
                                    <td style="text-align: left;"><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                    <?php foreach ($disciplinas as $disc): ?>
                                        <td>
                                            <?php 
                                            $nota = isset($aluno['notas'][$disc['id']]) ? $aluno['notas'][$disc['id']] : '-';
                                            echo $nota !== '-' ? number_format($nota, 1) : '-';
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td><strong><?php echo number_format($aluno['media_final'], 1); ?></strong></td>
                                    <td>
                                        <?php if ($aluno['resultado_final'] == 'Aprovado'): ?>
                                            <span class="badge-aprovado"><i class="fas fa-check-circle"></i> Aprovado</span>
                                        <?php elseif ($aluno['resultado_final'] == 'Reprovado'): ?>
                                            <span class="badge-reprovado"><i class="fas fa-times-circle"></i> Reprovado</span>
                                        <?php else: ?>
                                            <span class="badge-recuperacao"><i class="fas fa-exclamation-triangle"></i> Recuperação</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php elseif ($turma_selecionada && empty($alunos)): ?>
        <div class="empty-state">
            <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
            <h5>Nenhum aluno encontrado</h5>
            <p class="text-muted">Não há alunos matriculados nesta turma para o ano letivo de <?php echo $ano_selecionado; ?></p>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>