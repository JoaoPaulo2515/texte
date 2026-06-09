<?php
// escola/relatorios/estatistico_alunos.php - Estatísticas de Alunos por Turma
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// Verificar se o usuário tem permissão de administrador
$tipos_permitidos = ['super_admin', 'admin_escola', 'administrador', 'diretor'];
if (!in_array($_SESSION['usuario_tipo'], $tipos_permitidos)) {
    die("Acesso negado. Apenas administradores podem acessar esta página.");
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo = $ano_letivo['ano'] ?? 1;

// ============================================
// FILTROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_letivo_filtro = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : $ano_letivo;

// ============================================
// BUSCAR TURMAS DA ESCOLA
// ============================================
$sql_turmas = "
    SELECT 
        t.id, t.nome, t.ano, t.turno, t.sala,
        COUNT(m.id) as total_alunos
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
    WHERE t.escola_id = :escola_id
    GROUP BY t.id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS ESTATÍSTICOS
// ============================================
$estatisticas = [
    'total_alunos' => 0,
    'masculino' => 0,
    'feminino' => 0,
    'por_idade' => [],
    'por_mes_nascimento' => array_fill(1, 12, 0),
    'por_turno' => ['manhã' => 0, 'tarde' => 0, 'noite' => 0],
    'por_ano' => [],
    'media_idade' => 0,
    'idade_min' => 0,
    'idade_max' => 0,
    'total_aniversariantes_mes' => 0,
    'proximos_aniversariantes' => []
];

$alunos_detalhes = [];
$turma_info = null;

if ($turma_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "SELECT nome, ano, turno, sala, capacidade FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.data_nascimento,
            e.genero,
            e.bi,
            e.pai_nome,
            e.mae_nome,
            e.telefone,
            e.email,
            e.endereco,
            m.data_matricula
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id 
        AND m.status = 'ativa'
        AND m.ano_letivo = :ano_letivo
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo' => $ano_letivo_filtro
    ]);
    $alunos_detalhes = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas detalhadas
    $estatisticas['total_alunos'] = count($alunos_detalhes);
    $soma_idades = 0;
    $idades = [];
    $data_atual = new DateTime();
    
    foreach ($alunos_detalhes as $aluno) {
        // Estatísticas por genero
        if ($aluno['genero'] == 'masculino') {
            $estatisticas['masculino']++;
        } elseif ($aluno['genero'] == 'feminino') {
            $estatisticas['feminino']++;
        }
        
        // Estatísticas por turno
        if ($turma_info && isset($turma_info['turno'])) {
            $turno = strtolower($turma_info['turno']);
            if (isset($estatisticas['por_turno'][$turno])) {
                $estatisticas['por_turno'][$turno]++;
            }
        }
        
        // Estatísticas por ano (série)
        if ($turma_info && isset($turma_info['ano'])) {
            $ano_serie = $turma_info['ano'] . 'ª';
            if (!isset($estatisticas['por_ano'][$ano_serie])) {
                $estatisticas['por_ano'][$ano_serie] = 0;
            }
            $estatisticas['por_ano'][$ano_serie]++;
        }
        
        // Calcular idade
        if (!empty($aluno['data_nascimento'])) {
            $data_nasc = new DateTime($aluno['data_nascimento']);
            $idade = $data_nasc->diff($data_atual)->y;
            $idades[] = $idade;
            $soma_idades += $idade;
            
            // Por faixa etária
            $faixa = floor($idade / 5) * 5;
            $faixa_key = $faixa . '-' . ($faixa + 4) . ' anos';
            if (!isset($estatisticas['por_idade'][$faixa_key])) {
                $estatisticas['por_idade'][$faixa_key] = 0;
            }
            $estatisticas['por_idade'][$faixa_key]++;
            
            // Por mês de nascimento
            $mes = (int)$data_nasc->format('m');
            $estatisticas['por_mes_nascimento'][$mes]++;
            
            // Verificar aniversariantes do mês atual
            $mes_atual = (int)$data_atual->format('m');
            if ($mes == $mes_atual) {
                $estatisticas['total_aniversariantes_mes']++;
                
                // Próximos aniversariantes (próximos 30 dias)
                $dia_nasc = (int)$data_nasc->format('d');
                $dia_atual = (int)$data_atual->format('d');
                $data_aniversario = new DateTime($data_atual->format('Y') . '-' . $mes . '-' . $dia_nasc);
                
                if ($data_aniversario >= $data_atual) {
                    $dias_para = $data_atual->diff($data_aniversario)->days;
                    if ($dias_para <= 30) {
                        $estatisticas['proximos_aniversariantes'][] = [
                            'nome' => $aluno['nome'],
                            'data' => $data_aniversario->format('d/m/Y'),
                            'dias' => $dias_para,
                            'idade' => $idade
                        ];
                    }
                }
            }
        }
    }
    
    // Calcular médias e extremos
    if ($estatisticas['total_alunos'] > 0) {
        $estatisticas['media_idade'] = round($soma_idades / $estatisticas['total_alunos'], 1);
        $estatisticas['idade_min'] = !empty($idades) ? min($idades) : 0;
        $estatisticas['idade_max'] = !empty($idades) ? max($idades) : 0;
    }
    
    // Ordenar próximos aniversariantes por dias
    usort($estatisticas['proximos_aniversariantes'], function($a, $b) {
        return $a['dias'] - $b['dias'];
    });
    
    // Calcular porcentagens
    $estatisticas['percentual_masculino'] = $estatisticas['total_alunos'] > 0 ? 
        round(($estatisticas['masculino'] / $estatisticas['total_alunos']) * 100, 1) : 0;
    $estatisticas['percentual_feminino'] = $estatisticas['total_alunos'] > 0 ? 
        round(($estatisticas['feminino'] / $estatisticas['total_alunos']) * 100, 1) : 0;
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);

$meses_nomes = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 
                'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas de Alunos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Sidebar Menu */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            gap: 12px;
            transition: all 0.3s;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
                position: fixed;
                top: 20px;
                left: 20px;
                z-index: 1001;
                background: #006B3E;
                color: white;
                border: none;
                width: 40px;
                height: 40px;
                border-radius: 8px;
                cursor: pointer;
            }
        }
        
        .menu-toggle {
            display: none;
        }
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        /* Stat Cards */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: transform 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #006B3E;
        }
        
        .stat-label {
            color: #666;
            font-size: 13px;
            margin-top: 5px;
        }
        
        .stat-icon {
            font-size: 40px;
            color: #006B3E;
            margin-bottom: 10px;
        }
        
        /* Chart Containers */
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .chart-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
            border-left: 4px solid #006B3E;
            padding-left: 10px;
        }
        
        .progress-bar-custom {
            height: 25px;
            border-radius: 5px;
        }
        
        .aniversariante-item {
            border-left: 3px solid #ffc107;
            padding: 8px 12px;
            margin-bottom: 8px;
            background: #fff8e1;
            border-radius: 5px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chart-line"></i> Estatísticas de Alunos</h2>
            <div class="no-print">
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Selecione a Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Selecione uma turma --</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome']) . ' (' . ucfirst($turma['turno']) . ') - ' . $turma['total_alunos'] . ' alunos'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano_letivo" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anos_letivos as $ano): ?>
                        <option value="<?php echo $ano['ano']; ?>" <?php echo $ano_letivo_filtro == $ano['id'] ? 'selected' : ''; ?>>
                            <?php echo $ano['ano']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($turma_id > 0 && $estatisticas['total_alunos'] > 0): ?>
        
        <!-- Cards de Resumo -->
        <div class="row mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?php echo $estatisticas['total_alunos']; ?></div>
                    <div class="stat-label">Total de Alunos</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-mars text-primary"></i>
                    </div>
                    <div class="stat-number"><?php echo $estatisticas['masculino']; ?></div>
                    <div class="stat-label">Masculino (<?php echo $estatisticas['percentual_masculino']; ?>%)</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-venus text-danger"></i>
                    </div>
                    <div class="stat-number"><?php echo $estatisticas['feminino']; ?></div>
                    <div class="stat-label">Feminino (<?php echo $estatisticas['percentual_feminino']; ?>%)</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-number"><?php echo $estatisticas['media_idade']; ?></div>
                    <div class="stat-label">Idade Média (anos)</div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <!-- Gráfico de Pizza - Gênero -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-venus-mars"></i> Distribuição por Gênero
                    </div>
                    <canvas id="generoChart" style="height: 250px;"></canvas>
                </div>
            </div>
            
            <!-- Gráfico de Barras - Idade -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-chart-bar"></i> Distribuição por Faixa Etária
                    </div>
                    <canvas id="idadeChart" style="height: 250px;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Gráfico de Barras - Mês de Nascimento -->
            <div class="col-md-12">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-birthday-cake"></i> Distribuição por Mês de Nascimento
                    </div>
                    <canvas id="nascimentoChart" style="height: 300px;"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Informações Detalhadas -->
        <div class="row">
            <!-- Aniversariantes do Mês -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-gift"></i> Aniversariantes do Mês
                        <span class="badge bg-warning ms-2"><?php echo $estatisticas['total_aniversariantes_mes']; ?> alunos</span>
                    </div>
                    <?php if ($estatisticas['total_aniversariantes_mes'] > 0): ?>
                        <div class="mb-3">
                            <?php 
                            $mes_atual = $meses_nomes[(int)date('m') - 1];
                            echo "<p class='text-muted'>Alunos que fazem aniversário em <strong>$mes_atual</strong>:</p>";
                            ?>
                        </div>
                        <?php foreach ($alunos_detalhes as $aluno): 
                            $mes_nasc = !empty($aluno['data_nascimento']) ? (int)date('m', strtotime($aluno['data_nascimento'])) : 0;
                            if ($mes_nasc == date('m')): ?>
                            <div class="aniversariante-item">
                                <i class="fas fa-birthday-cake text-warning"></i>
                                <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                <span class="text-muted"> - Data: <?php echo date('d/m/Y', strtotime($aluno['data_nascimento'])); ?></span>
                            </div>
                        <?php endif; endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">Nenhum aniversariante neste mês.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Próximos Aniversariantes -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-calendar-week"></i> Próximos Aniversariantes (30 dias)
                    </div>
                    <?php if (!empty($estatisticas['proximos_aniversariantes'])): ?>
                        <?php foreach ($estatisticas['proximos_aniversariantes'] as $aniversariante): ?>
                        <div class="aniversariante-item">
                            <i class="fas fa-bell text-info"></i>
                            <strong><?php echo htmlspecialchars($aniversariante['nome']); ?></strong>
                            <span class="text-muted">
                                - <?php echo $aniversariante['data']; ?> 
                                (em <?php echo $aniversariante['dias']; ?> dias) - 
                                <?php echo $aniversariante['idade'] + 1; ?> anos
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">Nenhum aniversariante nos próximos 30 dias.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Alunos com Detalhes -->
        <div class="chart-container">
            <div class="chart-title">
                <i class="fas fa-table"></i> Detalhamento dos Alunos
                <span class="badge bg-secondary ms-2"><?php echo $estatisticas['total_alunos']; ?> registros</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-striped" id="tabelaAlunos">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Matrícula</th>
                            <th>Nome Completo</th>
                            <th>Genero</th>
                            <th>Data Nasc.</th>
                            <th>Idade</th>
                            <th>BI</th>
                            <th>Telefone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alunos_detalhes as $index => $aluno): 
                            $idade = !empty($aluno['data_nascimento']) ? 
                                (new DateTime($aluno['data_nascimento']))->diff(new DateTime())->y : 0;
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                            <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                            <td>
                                <?php if ($aluno['genero'] == 'masculino'): ?>
                                    <i class="fas fa-mars text-primary"></i> M
                                <?php elseif ($aluno['genero'] == 'feminino'): ?>
                                    <i class="fas fa-venus text-danger"></i> F
                                <?php else: ?>
                                    ---
                                <?php endif; ?>
                            </td>
                            <td><?php echo $aluno['data_nascimento'] ? date('d/m/Y', strtotime($aluno['data_nascimento'])) : '---'; ?></td>
                            <td><?php echo $idade; ?> anos</td>
                            <td><?php echo htmlspecialchars($aluno['bi'] ?: '---'); ?></td>
                            <td><?php echo htmlspecialchars($aluno['telefone'] ?: '---'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum aluno encontrado nesta turma para o ano letivo selecionado.
            </div>
        <?php else: ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma para visualizar as estatísticas.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Menu Toggle para Mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }
        
        <?php if ($turma_id > 0 && $estatisticas['total_alunos'] > 0): ?>
        
        // Gráfico de Gênero
        const ctxGenero = document.getElementById('generoChart').getContext('2d');
        new Chart(ctxGenero, {
            type: 'doughnut',
            data: {
                labels: ['Masculino', 'Feminino'],
                datasets: [{
                    data: [<?php echo $estatisticas['masculino']; ?>, <?php echo $estatisticas['feminino']; ?>],
                    backgroundColor: ['#006B3E', '#1A2A6C'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Gráfico de Idade
        const idadesLabels = <?php echo json_encode(array_keys($estatisticas['por_idade'])); ?>;
        const idadesValues = <?php echo json_encode(array_values($estatisticas['por_idade'])); ?>;
        
        const ctxIdade = document.getElementById('idadeChart').getContext('2d');
        new Chart(ctxIdade, {
            type: 'bar',
            data: {
                labels: idadesLabels,
                datasets: [{
                    label: 'Número de Alunos',
                    data: idadesValues,
                    backgroundColor: '#006B3E',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        
        // Gráfico de Mês de Nascimento
        const mesesLabels = <?php echo json_encode($meses_nomes); ?>;
        const mesesValues = <?php echo json_encode(array_values($estatisticas['por_mes_nascimento'])); ?>;
        
        const ctxNascimento = document.getElementById('nascimentoChart').getContext('2d');
        new Chart(ctxNascimento, {
            type: 'bar',
            data: {
                labels: mesesLabels,
                datasets: [{
                    label: 'Número de Aniversariantes',
                    data: mesesValues,
                    backgroundColor: '#1A2A6C',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        
        <?php endif; ?>
    </script>
</body>
</html>