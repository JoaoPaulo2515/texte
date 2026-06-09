<?php
// escola/pedagogico/disciplinas_turma.php - Disciplinas por Turma

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

// Parâmetros de filtro
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$classe_filtro = isset($_GET['classe']) ? (int)$_GET['classe'] : 0;

// Buscar anos letivos
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Se não tem ano letivo selecionado, pegar o mais recente
if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// Buscar turmas da escola
$sql_turmas = "
    SELECT 
        t.id,
        t.nome,
        t.ano,
        tr.nome as turno_nome,
        t.sala,
        t.capacidade,
        t.numero_alunos,
        (SELECT COUNT(DISTINCT dt.disciplina_id) FROM disciplina_turma dt WHERE dt.turma_id = t.id) as total_disciplinas
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
";

$params = [':escola_id' => $escola_id];

if ($classe_filtro > 0) {
    $sql_turmas .= " AND t.ano = :classe";
    $params[':classe'] = $classe_filtro;
}

$sql_turmas .= " ORDER BY t.ano ASC, t.nome ASC";

$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute($params);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Para cada turma, buscar suas disciplinas
$turmas_com_disciplinas = [];
foreach ($turmas as $turma) {
    $sql_disciplinas = "
        SELECT 
            d.id,
            d.nome,
            d.codigo,
            d.carga_horaria,
            d.cor,
            d.descricao,
            p.nome as professor_nome,
            p.id as professor_id,
            p.email as professor_email,
            pdt.status as atribuicao_status,
            pdt.dia_semana,
            pdt.horario_inicio,
            pdt.horario_fim
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        LEFT JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id AND pdt.turma_id = dt.turma_id AND pdt.ano_letivo_id = :ano_letivo_id
        LEFT JOIN funcionarios p ON p.id = pdt.professor_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([
        ':turma_id' => $turma['id'],
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    $turmas_com_disciplinas[] = [
        'turma' => $turma,
        'disciplinas' => $disciplinas
    ];
}

// Estatísticas
$total_turmas = count($turmas);
$total_disciplinas_geral = 0;
$total_professores_atribuidos = 0;

foreach ($turmas_com_disciplinas as $tc) {
    $total_disciplinas_geral += count($tc['disciplinas']);
    foreach ($tc['disciplinas'] as $disc) {
        if ($disc['professor_id']) {
            $total_professores_atribuidos++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disciplinas por Turma - SIGE Angola</title>
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
        
        .btn-limpar {
            background: #95a5a6;
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
            text-decoration: none;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        .stat-card.green { border-bottom: 4px solid #27ae60; }
        .stat-card.orange { border-bottom: 4px solid #f39c12; }
        .stat-card.purple { border-bottom: 4px solid #9b59b6; }
        
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
            padding: 20px;
        }
        
        .accordion-item {
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .accordion-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px 20px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .accordion-header:hover {
            background: #e9ecef;
        }
        
        .accordion-title {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .turma-nome {
            font-size: 16px;
            font-weight: bold;
            color: #1e5799;
        }
        
        .turma-info {
            font-size: 12px;
            color: #7f8c8d;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .accordion-icon {
            font-size: 20px;
            transition: transform 0.3s ease;
        }
        
        .accordion-content {
            display: none;
            padding: 20px;
            border-top: 1px solid #ecf0f1;
        }
        
        .accordion-content.active {
            display: block;
        }
        
        .table-disciplinas {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-disciplinas th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 13px;
        }
        
        .table-disciplinas td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
            font-size: 13px;
        }
        
        .table-disciplinas tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-atribuido {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .badge-nao-atribuido {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .badge-info {
            background: #d4e6f1;
            color: #1e5799;
        }
        
        .disciplina-cor {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            display: inline-block;
            margin-right: 8px;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px;
            color: #7f8c8d;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .btn-link-custom {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            background: #f0f2f5;
            color: #1e5799;
            text-decoration: none;
            border-radius: 20px;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        
        .btn-link-custom:hover {
            background: #1e5799;
            color: white;
        }
        
        .text-center {
            text-align: center;
        }
        
        .mt-3 {
            margin-top: 15px;
        }
        
        .table-responsive {
            overflow-x: auto;
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
            
            .accordion-title {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .turma-info {
                flex-direction: column;
                gap: 5px;
            }
            
            .table-disciplinas {
                font-size: 11px;
            }
            
            .table-disciplinas th, .table-disciplinas td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📚 Disciplinas por Turma</h1>
            <p>Visualize todas as disciplinas organizadas por turma</p>
        </div>
        <div>
            <a href="listar_disciplinas.php" class="btn-voltar">
                ← Voltar para Disciplinas
            </a>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filtros-card">
        <div class="filtros-header">
            🔍 Filtrar
        </div>
        <div class="filtros-body">
            <form method="GET" action="" class="filtros-row">
                <div class="filtro-group">
                    <label>Ano Letivo</label>
                    <select name="ano_letivo_id" class="filtro-select">
                        <option value="0">Todos os anos</option>
                        <?php foreach ($anos_letivos as $ano): ?>
                            <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ano['ano']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Classe</label>
                    <select name="classe" class="filtro-select">
                        <option value="0">Todas as classes</option>
                        <?php for ($i = 1; $i <= 13; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($classe_filtro == $i) ? 'selected' : ''; ?>>
                                <?php echo $i; ?>ª Classe
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
                    <a href="disciplinas_turma.php" class="btn-limpar">🗑️ Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-number"><?php echo $total_turmas; ?></div>
            <div class="stat-label">Total de Turmas</div>
        </div>
        <div class="stat-card green">
            <div class="stat-number"><?php echo $total_disciplinas_geral; ?></div>
            <div class="stat-label">Total de Disciplinas</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-number"><?php echo $total_professores_atribuidos; ?></div>
            <div class="stat-label">Professores Atribuídos</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-number"><?php echo $total_disciplinas_geral - $total_professores_atribuidos; ?></div>
            <div class="stat-label">Disciplinas sem Professor</div>
        </div>
    </div>
    
    <!-- Lista de Turmas com Disciplinas -->
    <div class="card">
        <div class="card-header">
            <span>📋 Turmas e suas Disciplinas</span>
        </div>
        <div class="card-body">
            <?php if (empty($turmas_com_disciplinas)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📚</div>
                    <p>Nenhuma turma encontrada.</p>
                </div>
            <?php else: ?>
                <?php foreach ($turmas_com_disciplinas as $index => $tc): 
                    $turma = $tc['turma'];
                    $disciplinas = $tc['disciplinas'];
                ?>
                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <div class="accordion-title">
                                <span class="turma-nome">
                                    <i class="fas fa-building"></i> 
                                    <?php echo $turma['ano']; ?>ª - <?php echo htmlspecialchars($turma['nome']); ?>
                                </span>
                                <div class="turma-info">
                                    <span><i class="fas fa-clock"></i> <?php echo ucfirst($turma['turno_nome']); ?></span>
                                    <span><i class="fas fa-door-open"></i> Sala: <?php echo $turma['sala'] ?? 'Não definida'; ?></span>
                                    <span><i class="fas fa-users"></i> <?php echo $turma['numero_alunos']; ?> alunos</span>
                                    <span><i class="fas fa-book"></i> <?php echo count($disciplinas); ?> disciplinas</span>
                                </div>
                            </div>
                            <div class="accordion-icon">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                        <div class="accordion-content">
                            <?php if (empty($disciplinas)): ?>
                                <div class="empty-state" style="padding: 30px;">
                                    <p>Nenhuma disciplina associada a esta turma.</p>
                                    <a href="atribuir_disciplinas.php?turma_id=<?php echo $turma['id']; ?>" class="btn-filtrar" style="display: inline-block; margin-top: 10px;">
                                        ➕ Atribuir Disciplinas
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table-disciplinas">
                                        <thead>
                                            <tr>
                                                <th width="30%">Disciplina</th>
                                                <th width="15%">Código</th>
                                                <th width="15%">Carga Horária</th>
                                                <th width="25%">Professor</th>
                                                <th width="15%">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($disciplinas as $disciplina): ?>
                                                <tr>
                                                    <td>
                                                        <div style="display: flex; align-items: center;">
                                                            <div class="disciplina-cor" style="background-color: <?php echo $disciplina['cor'] ?? '#1e5799'; ?>;"></div>
                                                            <strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong>
                                                        </div>
                                                        <?php if ($disciplina['descricao']): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($disciplina['descricao'], 0, 60)); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($disciplina['codigo']); ?></td>
                                                    <td><?php echo $disciplina['carga_horaria'] ? $disciplina['carga_horaria'] . 'h' : '---'; ?></td>
                                                    <td>
                                                        <?php if ($disciplina['professor_id']): ?>
                                                            <div>
                                                                <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($disciplina['professor_nome']); ?>
                                                                <?php if ($disciplina['professor_email']): ?>
                                                                    <br><small><?php echo htmlspecialchars($disciplina['professor_email']); ?></small>
                                                                <?php endif; ?>
                                                                <?php if ($disciplina['horario_inicio']): ?>
                                                                    <br><small><i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($disciplina['horario_inicio'])); ?> - <?php echo date('H:i', strtotime($disciplina['horario_fim'])); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="badge badge-nao-atribuido">
                                                                <i class="fas fa-user-slash"></i> Não atribuído
                                                            </span>
                                                            <br>
                                                            <a href="atribuir_professor_disciplina.php?turma_id=<?php echo $turma['id']; ?>&disciplina_id=<?php echo $disciplina['id']; ?>" class="btn-link-custom" style="margin-top: 5px;">
                                                                <i class="fas fa-user-plus"></i> Atribuir
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($disciplina['professor_id']): ?>
                                                            <span class="badge badge-atribuido">
                                                                <i class="fas fa-check-circle"></i> Professor atribuído
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge badge-nao-atribuido">
                                                                <i class="fas fa-exclamation-triangle"></i> Sem professor
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="atribuir_disciplinas.php?turma_id=<?php echo $turma['id']; ?>" class="btn-filtrar" style="display: inline-block;">
                                        <i class="fas fa-edit"></i> Gerenciar Disciplinas
                                    </a>
                                    <a href="atribuir_professor_disciplina.php?turma_id=<?php echo $turma['id']; ?>" class="btn-voltar" style="display: inline-block; background: #95a5a6; margin-left: 10px;">
                                        <i class="fas fa-user-tie"></i> Gerenciar Professores
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function toggleAccordion(element) {
        const content = element.nextElementSibling;
        const icon = element.querySelector('.accordion-icon i');
        
        content.classList.toggle('active');
        
        if (content.classList.contains('active')) {
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        } else {
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        }
    }
    
    // Abrir o primeiro accordion por padrão
    document.addEventListener('DOMContentLoaded', function() {
        const firstAccordion = document.querySelector('.accordion-content');
        if (firstAccordion) {
            firstAccordion.classList.add('active');
            const firstIcon = document.querySelector('.accordion-icon i');
            if (firstIcon) {
                firstIcon.classList.remove('fa-chevron-down');
                firstIcon.classList.add('fa-chevron-up');
            }
        }
    });
</script>
</body>
</html>