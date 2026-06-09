<?php
// escola/professor/provas/resultados_prova.php - Resultados da Prova

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];
$professor_nome = $professor['professor_nome'] ?? 'Professor';

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da prova
$sql_prova = "SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id AND p.professor_id = :funcionario_id AND p.escola_id = :escola_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([
    ':prova_id' => $prova_id, 
    ':funcionario_id' => $funcionario_id, 
    ':escola_id' => $escola_id
]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    // Redirecionar para página de erro com o ID da prova
    header('Location: erro_prova.php?codigo=404&msg=prova_nao_encontrada&id=' . $prova_id);
    exit;
}

// Filtros
$apenas_finalizadas = isset($_GET['finalizadas']) ? (int)$_GET['finalizadas'] : 1;
$busca_aluno = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR RESULTADOS DOS ALUNOS
// ==============================================
$sql_resultados = "SELECT 
                        t.id as tentativa_id,
                        t.tentativa_numero,
                        t.data_inicio,
                        t.data_fim,
                        t.data_entrega,
                        t.tempo_gasto_segundos,
                        t.pontuacao_total,
                        t.porcentagem,
                        t.aprovado,
                        t.status,
                        e.id as aluno_id,
                        e.nome as aluno_nome,
                        e.matricula as aluno_matricula
                    FROM online_provas_tentativas t
                    JOIN estudantes e ON e.id = t.aluno_id
                    WHERE t.prova_id = :prova_id";

if ($apenas_finalizadas == 1) {
    $sql_resultados .= " AND t.status = 'finalizada'";
}
if (!empty($busca_aluno)) {
    $sql_resultados .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca)";
}

$sql_resultados .= " ORDER BY t.pontuacao_total DESC, t.tempo_gasto_segundos ASC";

$stmt_resultados = $conn->prepare($sql_resultados);
$params = [':prova_id' => $prova_id];
if (!empty($busca_aluno)) {
    $params[':busca'] = "%$busca_aluno%";
}
$stmt_resultados->execute($params);
$resultados = $stmt_resultados->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_alunos = count($resultados);
$total_aprovados = 0;
$total_reprovados = 0;
$total_abandonadas = 0;
$soma_notas = 0;
$melhor_nota = 0;
$melhor_aluno = '';
$pior_nota = 0;
$pior_aluno = '';

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
        
        if ($resultado['pontuacao_total'] > $melhor_nota) {
            $melhor_nota = $resultado['pontuacao_total'];
            $melhor_aluno = $resultado['aluno_nome'];
        }
        if ($resultado['pontuacao_total'] < $pior_nota) {
            $pior_nota = $resultado['pontuacao_total'];
            $pior_aluno = $resultado['aluno_nome'];
        }
    }
}

$media_notas = ($total_aprovados + $total_reprovados) > 0 ? round($soma_notas / ($total_aprovados + $total_reprovados), 1) : 0;
$taxa_aprovacao = ($total_aprovados + $total_reprovados) > 0 ? round(($total_aprovados / ($total_aprovados + $total_reprovados)) * 100, 1) : 0;

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Resultados da Prova - <?php echo htmlspecialchars($prova['titulo']); ?> | Professor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ============================================
           RESET E BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 20px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 15px;
            }
        }

        /* ============================================
           CABEÇALHO DA PÁGINA
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .page-header h4 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .page-header p {
            margin: 5px 0 0;
            opacity: 0.85;
            font-size: 0.85rem;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateX(-3px);
        }

        .btn-exportar {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-exportar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        /* ============================================
           CARDS DE ESTATÍSTICAS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px 20px;
            text-align: center;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }

        .stat-icon i {
            font-size: 28px;
            color: #006B3E;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* ============================================
           CARDS DE DESTAQUE (MELHOR/PIOR ALUNO)
        ============================================ */
        .highlight-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            height: 100%;
        }

        .highlight-card i {
            font-size: 40px;
            margin-bottom: 15px;
        }

        .highlight-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .highlight-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            color: #6c757d;
        }

        .highlight-name {
            margin-top: 10px;
            font-size: 0.85rem;
            color: #6c757d;
        }

        /* ============================================
           CARD DE FILTROS
        ============================================ */
        .filter-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .filter-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .filter-header i {
            margin-right: 10px;
        }

        .filter-body {
            padding: 25px;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        .form-check-input {
            width: 1.2rem;
            height: 1.2rem;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: #006B3E;
            border-color: #006B3E;
        }

        .form-check-label {
            font-weight: 500;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        /* ============================================
           TABELA DE RESULTADOS
        ============================================ */
        .results-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .results-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .results-header i {
            margin-right: 10px;
        }

        .badge-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
        }

        .table-container {
            padding: 0;
            overflow-x: auto;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        .results-table thead th {
            background: #f8f9fa;
            padding: 12px 15px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
            border-bottom: 2px solid #e9ecef;
            text-align: center;
        }

        .results-table tbody td {
            padding: 12px 15px;
            font-size: 0.85rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }

        .results-table tbody tr:hover {
            background: #f8f9fa;
        }

        .results-table tfoot td {
            background: #f8f9fa;
            padding: 12px 15px;
            font-weight: 700;
            border-top: 2px solid #e9ecef;
        }

        /* Status das linhas */
        .row-aprovado {
            background-color: rgba(40, 167, 69, 0.05) !important;
        }

        .row-reprovado {
            background-color: rgba(220, 53, 69, 0.05) !important;
        }

        .row-abandonada {
            background-color: rgba(108, 117, 125, 0.05) !important;
        }

        /* Badges de Status */
        .badge-status {
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .badge-aprovado {
            background: #28a745;
            color: white;
        }

        .badge-reprovado {
            background: #dc3545;
            color: white;
        }

        .badge-abandonada {
            background: #6c757d;
            color: white;
        }

        /* Progresso */
        .progress-small {
            width: 80px;
            height: 4px;
            border-radius: 10px;
            background: #e9ecef;
            margin-top: 5px;
        }

        .progress-bar-small {
            height: 4px;
            border-radius: 10px;
        }

        /* Cores de texto */
        .text-aprovado { color: #28a745; font-weight: bold; }
        .text-reprovado { color: #dc3545; font-weight: bold; }
        .text-abandonada { color: #6c757d; font-weight: bold; }

        /* Botão de ações */
        .btn-detalhes {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-detalhes:hover {
            background: #138496;
            transform: translateY(-1px);
            color: white;
        }

        /* ============================================
           ALERTA VAZIO
        ============================================ */
        .empty-alert {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            border-radius: 20px;
            padding: 50px 20px;
            text-align: center;
        }

        .empty-alert i {
            font-size: 4rem;
            color: #006B3E;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-alert h5 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .results-table thead th,
            .results-table tbody td {
                padding: 8px 10px;
                font-size: 0.75rem;
            }
            
            .badge-status {
                padding: 3px 8px;
                font-size: 0.6rem;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .results-header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Cabeçalho -->
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="fas fa-chart-line me-2"></i> Resultados da Prova</h4>
                        <p><strong><?php echo htmlspecialchars($prova['titulo']); ?></strong> - <?php echo $prova['disciplina_nome']; ?></p>
                        <p class="small mb-0">Turma: <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="listar_provas.php" class="btn-voltar">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                        <button class="btn-exportar" onclick="exportarResultados()">
                            <i class="fas fa-file-excel"></i> Exportar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Cards de Estatísticas Principais -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value text-primary"><?php echo $total_alunos; ?></div>
                    <div class="stat-label">Total de Alunos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value text-success"><?php echo $total_aprovados; ?></div>
                    <div class="stat-label">Aprovados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-value text-danger"><?php echo $total_reprovados; ?></div>
                    <div class="stat-label">Reprovados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-hourglass-end"></i></div>
                    <div class="stat-value text-secondary"><?php echo $total_abandonadas; ?></div>
                    <div class="stat-label">Abandonaram</div>
                </div>
            </div>

            <!-- Estatísticas Detalhadas -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo number_format($media_notas, 1); ?></div>
                    <div class="stat-label">Média das Notas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $taxa_aprovacao; ?>%</div>
                    <div class="stat-label">Taxa de Aprovação</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $prova['nota_minima_aprovacao']; ?></div>
                    <div class="stat-label">Nota Mínima para Aprovação</div>
                </div>
            </div>

            <!-- Melhor e Pior Aluno -->
            <div class="row g-3 mb-4 fade-in">
                <div class="col-md-6">
                    <div class="highlight-card">
                        <i class="fas fa-trophy" style="color: #ffc107;"></i>
                        <div class="highlight-value text-success"><?php echo number_format($melhor_nota, 1); ?></div>
                        <div class="highlight-label">Melhor Nota</div>
                        <div class="highlight-name"><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($melhor_aluno); ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="highlight-card">
                        <i class="fas fa-chart-line" style="color: #17a2b8;"></i>
                        <div class="highlight-value text-danger"><?php echo number_format($pior_nota, 1); ?></div>
                        <div class="highlight-label">Pior Nota</div>
                        <div class="highlight-name"><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($pior_aluno); ?></div>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filter-card fade-in">
                <div class="filter-header">
                    <i class="fas fa-filter"></i> Filtros de Busca
                </div>
                <div class="filter-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <input type="hidden" name="id" value="<?php echo $prova_id; ?>">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="apenas_finalizadas" name="finalizadas" value="1" <?php echo $apenas_finalizadas == 1 ? 'checked' : ''; ?> onchange="this.form.submit()">
                                <label class="form-check-label" for="apenas_finalizadas">
                                    <i class="fas fa-check-circle"></i> Apenas provas finalizadas
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Buscar por aluno</label>
                            <input type="text" name="busca" class="form-control" placeholder="Digite o nome ou matrícula..." value="<?php echo htmlspecialchars($busca_aluno); ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filtrar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista de Resultados -->
            <div class="results-card fade-in">
                <div class="results-header">
                    <div><i class="fas fa-list"></i> Desempenho dos Alunos</div>
                    <div><span class="badge-count"><?php echo count($resultados); ?> registros</span></div>
                </div>
                <div class="table-container">
                    <?php if (empty($resultados)): ?>
                        <div class="empty-alert m-4">
                            <i class="fas fa-info-circle"></i>
                            <h5>Nenhum resultado encontrado</h5>
                            <p class="mb-0">Não há registros de tentativas para esta prova com os filtros selecionados.</p>
                        </div>
                    <?php else: ?>
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Aluno</th>
                                    <th>Matrícula</th>
                                    <th>Tentativa</th>
                                    <th>Data</th>
                                    <th>Tempo</th>
                                    <th>Nota</th>
                                    <th>%</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados as $index => $resultado): 
                                    $porcentagem = round($resultado['porcentagem'], 1);
                                    $cor_nota = $resultado['aprovado'] == 1 ? 'text-aprovado' : ($resultado['status'] == 'abandonada' ? 'text-abandonada' : 'text-reprovado');
                                    $row_class = $resultado['aprovado'] == 1 ? 'row-aprovado' : ($resultado['status'] == 'abandonada' ? 'row-abandonada' : 'row-reprovado');
                                    $tempo_formatado = gmdate("H:i:s", $resultado['tempo_gasto_segundos']);
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td><?php echo $index + 1; ?></td>
                                    <td class="text-start"><strong><?php echo htmlspecialchars($resultado['aluno_nome']); ?></strong></td>
                                    <td><?php echo $resultado['aluno_matricula']; ?></td>
                                    <td class="text-center"><?php echo $resultado['tentativa_numero']; ?>ª</td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($resultado['data_entrega'] ?? $resultado['data_fim'])); ?></td>
                                    <td class="text-center"><?php echo $tempo_formatado; ?></td>
                                    <td class="text-center fw-bold <?php echo $cor_nota; ?>">
                                        <?php echo number_format($resultado['pontuacao_total'], 1); ?> / <?php echo $prova['nota_maxima']; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo $porcentagem; ?>%
                                        <div class="progress-small mx-auto">
                                            <div class="progress-bar-small <?php echo $resultado['aprovado'] == 1 ? 'bg-success' : 'bg-danger'; ?>" style="width: <?php echo $porcentagem; ?>%;"></div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($resultado['status'] == 'abandonada'): ?>
                                            <span class="badge-status badge-abandonada"><i class="fas fa-hourglass-end"></i> Abandonou</span>
                                        <?php elseif ($resultado['aprovado'] == 1): ?>
                                            <span class="badge-status badge-aprovado"><i class="fas fa-check-circle"></i> Aprovado</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-reprovado"><i class="fas fa-times-circle"></i> Reprovado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="detalhes_aluno_prova.php?id=<?php echo $resultado['tentativa_id']; ?>" class="btn-detalhes" target="_blank">
                                            <i class="fas fa-eye"></i> Detalhes
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-end fw-bold">Média Geral:</td>
                                    <td class="text-center fw-bold text-info">--</td>
                                    <td class="text-center fw-bold"><?php echo number_format($media_notas, 1); ?></td>
                                    <td class="text-center fw-bold"><?php echo $media_notas > 0 ? round(($media_notas / $prova['nota_maxima']) * 100, 1) : 0; ?>%</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function exportarResultados() {
            window.location.href = 'exportar_resultados.php?id=<?php echo $prova_id; ?>&finalizadas=<?php echo $apenas_finalizadas; ?>&busca=<?php echo urlencode($busca_aluno); ?>';
        }
        
        // Auto-submit ao pressionar Enter na busca
        document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });
        
        // Adicionar animações aos elementos ao rolar
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.stat-card, .highlight-card, .filter-card, .results-card').forEach(card => {
            card.classList.remove('fade-in');
            observer.observe(card);
        });
    </script>
</body>
</html>