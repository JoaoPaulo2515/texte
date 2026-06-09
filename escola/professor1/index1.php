<?php
// escola/professorlancamento/index.php
session_start();

// Verificar se professor está logado
if (!isset($_SESSION['professor_id'])) {
    header('Location: ../login.php');
    exit;
}

$professor_id = $_SESSION['professor_id'];
$professor_nome = $_SESSION['professor_nome'] ?? 'Professor';

// Conexão com banco de dados
$host = 'localhost';
$dbname = 'escola';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

// 1. Buscar turmas do professor logado
$sql_turmas = "
    SELECT DISTINCT 
        t.id, 
        t.nome, 
        t.serie, 
        t.ano_letivo,
        d.nome AS disciplina_nome,
        d.id AS disciplina_id
    FROM turmas t
    INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
    INNER JOIN disciplinas d ON d.id = dt.disciplina_id
    INNER JOIN professor_disciplina pd ON pd.disciplina_id = d.id
    WHERE pd.professor_id = :professor_id
    ORDER BY t.ano_letivo DESC, t.serie, t.nome
";

$stmt_turmas = $pdo->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// 2. Buscar alunos das turmas do professor
$sql_alunos = "
    SELECT 
        a.id, 
        a.nome, 
        a.matricula,
        a.email,
        t.id AS turma_id,
        t.nome AS turma_nome
    FROM alunos a
    INNER JOIN turmas t ON t.id = a.turma_id
    INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
    INNER JOIN professor_disciplina pd ON pd.disciplina_id = dt.disciplina_id
    WHERE pd.professor_id = :professor_id
    ORDER BY t.nome, a.nome
";

$stmt_alunos = $pdo->prepare($sql_alunos);
$stmt_alunos->execute([':professor_id' => $professor_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// 3. Buscar atividades/lançamentos do professor
$sql_atividades = "
    SELECT 
        a.id,
        a.titulo,
        a.descricao,
        a.data_entrega,
        a.valor_maximo,
        a.tipo,
        d.nome AS disciplina_nome,
        t.nome AS turma_nome
    FROM atividades a
    INNER JOIN disciplinas d ON d.id = a.disciplina_id
    INNER JOIN turmas t ON t.id = a.turma_id
    INNER JOIN professor_disciplina pd ON pd.disciplina_id = d.id
    WHERE pd.professor_id = :professor_id
    ORDER BY a.data_entrega DESC
    LIMIT 20
";

$stmt_atividades = $pdo->prepare($sql_atividades);
$stmt_atividades->execute([':professor_id' => $professor_id]);
$atividades = $stmt_atividades->fetchAll(PDO::FETCH_ASSOC);

// 4. Buscar notas lançadas pelo professor (para estatísticas)
$sql_notas = "
    SELECT 
        n.id,
        n.nota,
        n.data_lancamento,
        a.titulo AS atividade_titulo,
        al.nome AS aluno_nome,
        d.nome AS disciplina_nome
    FROM notas n
    INNER JOIN atividades a ON a.id = n.atividade_id
    INNER JOIN alunos al ON al.id = n.aluno_id
    INNER JOIN disciplinas d ON d.id = a.disciplina_id
    INNER JOIN professor_disciplina pd ON pd.disciplina_id = d.id
    WHERE pd.professor_id = :professor_id
    ORDER BY n.data_lancamento DESC
    LIMIT 50
";

$stmt_notas = $pdo->prepare($sql_notas);
$stmt_notas->execute([':professor_id' => $professor_id]);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// 5. Estatísticas do professor
$sql_stats = "
    SELECT 
        (SELECT COUNT(DISTINCT t.id) 
         FROM turmas t
         INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
         INNER JOIN professor_disciplina pd ON pd.disciplina_id = dt.disciplina_id
         WHERE pd.professor_id = :professor_id) AS total_turmas,
        
        (SELECT COUNT(DISTINCT a.id) 
         FROM alunos a
         INNER JOIN turmas t ON t.id = a.turma_id
         INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
         INNER JOIN professor_disciplina pd ON pd.disciplina_id = dt.disciplina_id
         WHERE pd.professor_id = :professor_id) AS total_alunos,
        
        (SELECT COUNT(DISTINCT d.id) 
         FROM disciplinas d
         INNER JOIN professor_disciplina pd ON pd.disciplina_id = d.id
         WHERE pd.professor_id = :professor_id) AS total_disciplinas,
        
        (SELECT COUNT(*) 
         FROM atividades a
         INNER JOIN disciplinas d ON d.id = a.disciplina_id
         INNER JOIN professor_disciplina pd ON pd.disciplina_id = d.id
         WHERE pd.professor_id = :professor_id) AS total_atividades
";

$stmt_stats = $pdo->prepare($sql_stats);
$stmt_stats->execute([':professor_id' => $professor_id]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// 6. Médias por turma (para gráfico)
$sql_medias_turma = "
    SELECT 
        t.nome AS turma_nome,
        AVG(n.nota) AS media_geral,
        COUNT(DISTINCT n.aluno_id) AS alunos_com_nota,
        COUNT(DISTINCT a.id) AS total_atividades
    FROM turmas t
    LEFT JOIN disciplina_turma dt ON dt.turma_id = t.id
    LEFT JOIN atividades a ON a.turma_id = t.id
    LEFT JOIN notas n ON n.atividade_id = a.id
    LEFT JOIN professor_disciplina pd ON pd.disciplina_id = dt.disciplina_id
    WHERE pd.professor_id = :professor_id
    GROUP BY t.id
    ORDER BY media_geral DESC
";

$stmt_medias = $pdo->prepare($sql_medias_turma);
$stmt_medias->execute([':professor_id' => $professor_id]);
$medias_turma = $stmt_medias->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard do Professor - Lançamento de Notas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card-stats {
            transition: transform 0.2s;
            cursor: pointer;
        }
        .card-stats:hover {
            transform: translateY(-5px);
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .nav-link {
            color: white;
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .nav-link i {
            margin-right: 10px;
        }
        .content-wrapper {
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .table-responsive {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .btn-lancar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="text-center py-4">
                    <i class="bi bi-person-circle" style="font-size: 60px; color: white;"></i>
                    <h5 class="text-white mt-2"><?php echo htmlspecialchars($professor_nome); ?></h5>
                    <small class="text-white-50">Professor</small>
                </div>
                <hr class="bg-white">
                <nav class="nav flex-column px-3">
                    <a class="nav-link active" href="#" data-page="dashboard">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="#" data-page="turmas">
                        <i class="bi bi-building"></i> Minhas Turmas
                    </a>
                    <a class="nav-link" href="#" data-page="alunos">
                        <i class="bi bi-people"></i> Meus Alunos
                    </a>
                    <a class="nav-link" href="#" data-page="atividades">
                        <i class="bi bi-calendar-check"></i> Atividades
                    </a>
                    <a class="nav-link" href="#" data-page="notas">
                        <i class="bi bi-journal-bookmark-fill"></i> Lançar Notas
                    </a>
                    <a class="nav-link" href="#" data-page="relatorios">
                        <i class="bi bi-graph-up"></i> Relatórios
                    </a>
                    <hr class="bg-white">
                    <a class="nav-link" href="../logout.php">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="col-md-10 content-wrapper p-4">
                <!-- Dashboard Content -->
                <div id="dashboard-content">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2><i class="bi bi-speedometer2"></i> Dashboard Acadêmico</h2>
                        <div class="text-muted"><?php echo date('d/m/Y H:i'); ?></div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card card-stats bg-primary text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title">Minhas Turmas</h6>
                                            <h2><?php echo $stats['total_turmas'] ?? 0; ?></h2>
                                        </div>
                                        <i class="bi bi-building" style="font-size: 40px;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-stats bg-success text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title">Meus Alunos</h6>
                                            <h2><?php echo $stats['total_alunos'] ?? 0; ?></h2>
                                        </div>
                                        <i class="bi bi-people" style="font-size: 40px;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-stats bg-info text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title">Disciplinas</h6>
                                            <h2><?php echo $stats['total_disciplinas'] ?? 0; ?></h2>
                                        </div>
                                        <i class="bi bi-book" style="font-size: 40px;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-stats bg-warning text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title">Atividades</h6>
                                            <h2><?php echo $stats['total_atividades'] ?? 0; ?></h2>
                                        </div>
                                        <i class="bi bi-calendar" style="font-size: 40px;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <div class="chart-container">
                                <h5>Médias das Turmas</h5>
                                <canvas id="mediasChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="chart-container">
                                <h5>Distribuição de Alunos</h5>
                                <canvas id="alunosChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activities -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="table-responsive">
                                <h5><i class="bi bi-calendar-check"></i> Últimas Atividades</h5>
                                <table class="table table-sm">
                                    <thead>
                                        <tr><th>Título</th><th>Turma</th><th>Data Entrega</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach(array_slice($atividades, 0, 5) as $atividade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($atividade['titulo']); ?></td>
                                            <td><?php echo htmlspecialchars($atividade['turma_nome']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($atividade['data_entrega'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if(count($atividades) == 0): ?>
                                        <tr><td colspan="3" class="text-center">Nenhuma atividade encontrada</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="table-responsive">
                                <h5><i class="bi bi-journal-bookmark-fill"></i> Últimos Lançamentos</h5>
                                <table class="table table-sm">
                                    <thead>
                                        <tr><th>Aluno</th><th>Atividade</th><th>Nota</th><th>Data</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach(array_slice($notas, 0, 5) as $nota): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($nota['aluno_nome']); ?></td>
                                            <td><?php echo htmlspecialchars($nota['atividade_titulo']); ?></td>
                                            <td><?php echo number_format($nota['nota'], 1); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($nota['data_lancamento'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if(count($notas) == 0): ?>
                                        <tr><td colspan="4" class="text-center">Nenhuma nota lançada</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Turmas Content -->
                <div id="turmas-content" style="display: none;">
                    <h2><i class="bi bi-building"></i> Minhas Turmas</h2>
                    <div class="table-responsive mt-3">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>ID</th><th>Turma</th><th>Série</th><th>Ano Letivo</th><th>Disciplina</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($turmas as $turma): ?>
                                <tr>
                                    <td><?php echo $turma['id']; ?></td>
                                    <td><?php echo htmlspecialchars($turma['nome']); ?></td>
                                    <td><?php echo $turma['serie'] . 'ª'; ?></td>
                                    <td><?php echo $turma['ano_letivo']; ?></td>
                                    <td><?php echo htmlspecialchars($turma['disciplina_nome']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="verTurma(<?php echo $turma['id']; ?>)">
                                            <i class="bi bi-eye"></i> Ver
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Alunos Content -->
                <div id="alunos-content" style="display: none;">
                    <h2><i class="bi bi-people"></i> Meus Alunos</h2>
                    <div class="table-responsive mt-3">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>Matrícula</th><th>Nome</th><th>Email</th><th>Turma</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($alunos as $aluno): ?>
                                <tr>
                                    <td><?php echo $aluno['matricula']; ?></td>
                                    <td><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($aluno['email']); ?></td>
                                    <td><?php echo htmlspecialchars($aluno['turma_nome']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="verAluno(<?php echo $aluno['id']; ?>)">
                                            <i class="bi bi-journal"></i> Histórico
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Atividades Content -->
                <div id="atividades-content" style="display: none;">
                    <div class="d-flex justify-content-between mb-3">
                        <h2><i class="bi bi-calendar-check"></i> Minhas Atividades</h2>
                        <button class="btn btn-lancar" data-bs-toggle="modal" data-bs-target="#novaAtividadeModal">
                            <i class="bi bi-plus-circle"></i> Nova Atividade
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>Título</th><th>Descrição</th><th>Disciplina</th><th>Turma</th><th>Data Entrega</th><th>Valor</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($atividades as $atividade): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($atividade['titulo']); ?></td>
                                    <td><?php echo substr(htmlspecialchars($atividade['descricao']), 0, 50); ?></td>
                                    <td><?php echo htmlspecialchars($atividade['disciplina_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($atividade['turma_nome']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($atividade['data_entrega'])); ?></td>
                                    <td><?php echo $atividade['valor_maximo']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success" onclick="lancarNotas(<?php echo $atividade['id']; ?>)">
                                            <i class="bi bi-pencil"></i> Lançar Notas
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Notas Content -->
                <div id="notas-content" style="display: none;">
                    <h2><i class="bi bi-journal-bookmark-fill"></i> Lançar Notas</h2>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <label>Selecione a Turma</label>
                            <select id="filtroTurma" class="form-select" onchange="carregarAlunosNotas()">
                                <option value="">Todas</option>
                                <?php foreach($turmas as $turma): ?>
                                <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label>Selecione a Disciplina</label>
                            <select id="filtroDisciplina" class="form-select" onchange="carregarAlunosNotas()">
                                <option value="">Todas</option>
                                <?php 
                                $disciplinas_unicas = [];
                                foreach($turmas as $turma):
                                    $key = $turma['disciplina_id'];
                                    if(!isset($disciplinas_unicas[$key])):
                                        $disciplinas_unicas[$key] = $turma['disciplina_nome'];
                                ?>
                                <option value="<?php echo $turma['disciplina_id']; ?>"><?php echo htmlspecialchars($turma['disciplina_nome']); ?></option>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </select>
                        </div>
                    </div>
                    <div id="lancamentoNotasArea" class="mt-3"></div>
                </div>

                <!-- Relatórios Content -->
                <div id="relatorios-content" style="display: none;">
                    <h2><i class="bi bi-graph-up"></i> Relatórios por Turma</h2>
                    <div class="table-responsive mt-3">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>Turma</th><th>Média Geral</th><th>Alunos Avaliados</th><th>Total Atividades</th><th>Relatório</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($medias_turma as $media): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($media['turma_nome']); ?></td>
                                    <td><?php echo number_format($media['media_geral'] ?? 0, 1); ?></td>
                                    <td><?php echo $media['alunos_com_nota']; ?></td>
                                    <td><?php echo $media['total_atividades']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" onclick="gerarRelatorioPDF(<?php echo $media['turma_nome']; ?>)">
                                            <i class="bi bi-file-pdf"></i> Gerar PDF
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Nova Atividade Modal -->
    <div class="modal fade" id="novaAtividadeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nova Atividade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formNovaAtividade">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Título</label>
                            <input type="text" name="titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Disciplina</label>
                            <select name="disciplina_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php 
                                $disciplinas_unicas = [];
                                foreach($turmas as $turma):
                                    $key = $turma['disciplina_id'];
                                    if(!isset($disciplinas_unicas[$key])):
                                        $disciplinas_unicas[$key] = $turma['disciplina_nome'];
                                ?>
                                <option value="<?php echo $turma['disciplina_id']; ?>"><?php echo htmlspecialchars($turma['disciplina_nome']); ?></option>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Turma</label>
                            <select name="turma_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach($turmas as $turma): ?>
                                <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Data de Entrega</label>
                            <input type="date" name="data_entrega" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Valor Máximo</label>
                            <input type="number" name="valor_maximo" class="form-control" step="0.5" value="10" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navegação entre páginas
        document.querySelectorAll('.nav-link[data-page]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = this.dataset.page;
                
                // Esconde todos os conteúdos
                document.querySelectorAll('[id$="-content"]').forEach(content => {
                    content.style.display = 'none';
                });
                
                // Mostra o conteúdo selecionado
                document.getElementById(`${page}-content`).style.display = 'block';
                
                // Ativa o link
                document.querySelectorAll('.nav-link').forEach(nav => nav.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Gráfico de médias das turmas
        const ctxMedias = document.getElementById('mediasChart').getContext('2d');
        const turmasNomes = <?php echo json_encode(array_column($medias_turma, 'turma_nome')); ?>;
        const turmasMedias = <?php echo json_encode(array_column($medias_turma, 'media_geral')); ?>;
        
        new Chart(ctxMedias, {
            type: 'bar',
            data: {
                labels: turmasNomes,
                datasets: [{
                    label: 'Média Geral',
                    data: turmasMedias,
                    backgroundColor: 'rgba(102, 126, 234, 0.5)',
                    borderColor: 'rgba(102, 126, 234, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 10
                    }
                }
            }
        });

        // Gráfico de alunos por turma
        const ctxAlunos = document.getElementById('alunosChart').getContext('2d');
        const turmasParaAlunos = <?php echo json_encode(array_column($turmas, 'nome')); ?>;
        const alunosPorTurma = <?php 
            $counts = [];
            foreach($turmas as $t) {
                $counts[] = count(array_filter($alunos, function($a) use ($t) {
                    return $a['turma_nome'] == $t['nome'];
                }));
            }
            echo json_encode($counts);
        ?>;
        
        new Chart(ctxAlunos, {
            type: 'pie',
            data: {
                labels: turmasParaAlunos,
                datasets: [{
                    data: alunosPorTurma,
                    backgroundColor: ['#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe']
                }]
            }
        });

        // Funções auxiliares
        function verTurma(id) {
            alert('Visualizar turma ' + id);
        }
        
        function verAluno(id) {
            alert('Visualizar histórico do aluno ' + id);
        }
        
        function lancarNotas(atividadeId) {
            alert('Lançar notas para atividade ' + atividadeId);
        }
        
        function carregarAlunosNotas() {
            const turma = document.getElementById('filtroTurma').value;
            const disciplina = document.getElementById('filtroDisciplina').value;
            document.getElementById('lancamentoNotasArea').innerHTML = '<div class="alert alert-info">Carregando alunos...</div>';
            // Aqui viria uma requisição AJAX para carregar os alunos e formulário de lançamento
        }
        
        function gerarRelatorioPDF(turma) {
            alert('Gerando relatório PDF para ' + turma);
        }
        
        // Submissão do formulário de nova atividade
        document.getElementById('formNovaAtividade')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('professor_id', <?php echo $professor_id; ?>);
            
            fetch('ajax/criar_atividade.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    alert('Atividade criada com sucesso!');
                    location.reload();
                } else {
                    alert('Erro: ' + data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        });
    </script>
</body>
</html>