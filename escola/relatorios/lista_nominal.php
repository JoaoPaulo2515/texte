<?php
// escola/relatorios/lista_nominal.php - Lista Nominal de Alunos por Turma
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

// ============================================
// FILTROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$tipo_lista = isset($_GET['tipo_lista']) ? $_GET['tipo_lista'] : 'completa';

// ============================================
// BUSCAR TURMAS DA ESCOLA
// ============================================
$sql_turmas = "
    SELECT 
        t.id, t.nome, t.ano, t.turno, t.sala, t.capacidade,
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
// BUSCAR ALUNOS DA TURMA COM ESTATÍSTICAS
// ============================================
$alunos = [];
$estatisticas = [
    'total' => 0,
    'masculino' => 0,
    'feminino' => 0,
    'data_nascimento' => [],
    'idade_media' => 0,
    'idades' => []
];

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
            e.pai_telefone,
            e.email,
            e.endereco,
            e.foto,
            m.data_matricula,
            m.status as matricula_status
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas
    $estatisticas['total'] = count($alunos);
    $soma_idades = 0;
    
    foreach ($alunos as $aluno) {
        // Estatísticas por genero
        if ($aluno['genero'] == 'masculino') {
            $estatisticas['masculino']++;
        } elseif ($aluno['genero'] == 'feminino') {
            $estatisticas['feminino']++;
        }
        
        // Calcular idade
        if (!empty($aluno['data_nascimento'])) {
            $data_nasc = new DateTime($aluno['data_nascimento']);
            $hoje = new DateTime();
            $idade = $data_nasc->diff($hoje)->y;
            $soma_idades += $idade;
            $estatisticas['idades'][] = $idade;
            
            $mes_nasc = $data_nasc->format('m');
            if (!isset($estatisticas['data_nascimento'][$mes_nasc])) {
                $estatisticas['data_nascimento'][$mes_nasc] = 0;
            }
            $estatisticas['data_nascimento'][$mes_nasc]++;
        }
    }
    
    $estatisticas['idade_media'] = $estatisticas['total'] > 0 ? round($soma_idades / $estatisticas['total'], 1) : 0;
    
    // Ordenar meses
    ksort($estatisticas['data_nascimento']);
    
    // Estatísticas de idade
    $estatisticas['menor_idade'] = !empty($estatisticas['idades']) ? min($estatisticas['idades']) : 0;
    $estatisticas['maior_idade'] = !empty($estatisticas['idades']) ? max($estatisticas['idades']) : 0;
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista Nominal de Alunos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
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
        
        /* Estatísticas Cards */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: transform 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #006B3E;
        }
        
        .stat-label {
            color: #666;
            font-size: 12px;
        }
        
        /* Tabela */
        .table-alunos th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-alunos td {
            vertical-align: middle;
        }
        
        /* Botões de Exportação */
        .btn-export {
            border-radius: 25px;
            padding: 10px 24px;
            margin: 5px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
        }
        
        .btn-pdf { background-color: #dc3545; color: white; border: none; }
        .btn-excel { background-color: #28a745; color: white; border: none; }
        .btn-doc { background-color: #007bff; color: white; border: none; }
        .btn-print { background-color: #17a2b8; color: white; border: none; }
        
        /* Gráfico de barras simples */
        .bar-chart {
            background: #e9ecef;
            border-radius: 20px;
            height: 8px;
            overflow: hidden;
        }
        
        .bar-fill {
            background: #006B3E;
            height: 100%;
            border-radius: 20px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .main-content {
                margin: 0;
                padding: 10px;
            }
            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-users"></i> Lista Nominal de Alunos</h2>
            <div class="no-print">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end" id="formFiltros">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Selecione a Turma</label>
                    <select name="turma_id" class="form-select" id="turma_id" onchange="this.form.submit()">
                        <option value="">-- Selecione uma turma --</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome']) . ' (' . ucfirst($turma['turno']) . ') - ' . $turma['total_alunos'] . ' alunos'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Tipo de Lista</label>
                    <select name="tipo_lista" class="form-select" id="tipo_lista" onchange="this.form.submit()">
                        <option value="completa" <?php echo $tipo_lista == 'completa' ? 'selected' : ''; ?>>Lista Completa (com dados dos pais)</option>
                        <option value="resumida" <?php echo $tipo_lista == 'resumida' ? 'selected' : ''; ?>>Lista Resumida (básico)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($turma_id > 0 && !empty($alunos)): ?>
        
        <!-- Botões de Exportação -->
        <div class="row mb-4 no-print">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="mb-3"><i class="fas fa-download"></i> Exportar Lista</h6>
                        <div class="d-flex justify-content-center flex-wrap">
                            <a href="gerar_pdf_lista.php?turma_id=<?php echo $turma_id; ?>&tipo_lista=<?php echo $tipo_lista; ?>" class="btn-export btn-pdf" target="_blank">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                            <a href="gerar_excel_lista.php?turma_id=<?php echo $turma_id; ?>&tipo_lista=<?php echo $tipo_lista; ?>" class="btn-export btn-excel">
                                <i class="fas fa-file-excel"></i> Excel
                            </a>
                            <a href="gerar_doc_lista.php?turma_id=<?php echo $turma_id; ?>&tipo_lista=<?php echo $tipo_lista; ?>" class="btn-export btn-doc">
                                <i class="fas fa-file-word"></i> DOC
                            </a>
                            <a href="view_print_lista.php?turma_id=<?php echo $turma_id; ?>&tipo_lista=<?php echo $tipo_lista; ?>" class="btn-export btn-print" target="_blank">
                                <i class="fas fa-print"></i> Visualizar e Imprimir
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas da Turma -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header" style="background: #006B3E; color: white;">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Estatísticas da Turma</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 col-6">
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo $estatisticas['total']; ?></div>
                                    <div class="stat-label">Total de Alunos</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo $estatisticas['masculino']; ?></div>
                                    <div class="stat-label">Masculino</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo $estatisticas['feminino']; ?></div>
                                    <div class="stat-label">Feminino</div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo $estatisticas['idade_media']; ?></div>
                                    <div class="stat-label">Idade Média</div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($estatisticas['data_nascimento'])){ ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6 class="mb-3">Distribuição por Mês de Nascimento</h6>
                                <div class="row">
                                    <?php 
                                    $meses_nomes = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
                                    for ($i = 1; $i <= 12; $i++){
                                        $qtd = $estatisticas['data_nascimento'][$i] ?? 0;
                                        $porcentagem = $estatisticas['total'] > 0 ? ($qtd / $estatisticas['total']) * 100 : 0;
                                    ?>
                                    <div class="col-md-3 col-sm-6 mb-2">
                                        <div class="d-flex justify-content-between small">
                                            <span><?php echo $meses_nomes[$i-1]; ?></span>
                                            <span><?php echo $qtd; ?> alunos (<?php echo round($porcentagem); ?>%)</span>
                                        </div>
                                        <div class="bar-chart">
                                            <div class="bar-fill" style="width: <?php echo $porcentagem; ?>%;"></div>
                                        </div>
                                    </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Lista de Alunos -->
        <div class="card">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Alunos - <?php echo $turma_info['ano'] . 'ª ' . htmlspecialchars($turma_info['nome']); ?></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-alunos" id="tabelaAlunos">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="12%">Matrícula</th>
                                <th width="25%">Nome Completo</th>
                                <?php if ($tipo_lista == 'completa'): ?>
                                <th width="8%">genero</th>
                                <th width="10%">Data Nasc.</th>
                                <th width="12%">BI</th>
                                <th width="15%">Nome do Pai</th>
                                <th width="13%">Nome da Mãe</th>
                                <?php else: ?>
                                <th width="10%">genero</th>
                                <th width="15%">Data Nascimento</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $index => $aluno): ?>
                            <tr>
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                <?php if ($tipo_lista == 'completa'): ?>
                                <td class="text-center">
                                    <?php if ($aluno['genero'] == 'masculino'): ?>
                                        <i class="fas fa-mars text-primary"></i> M
                                    <?php elseif ($aluno['genero'] == 'feminino'): ?>
                                        <i class="fas fa-venus text-danger"></i> F
                                    <?php else: ?>
                                        ---
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $aluno['data_nascimento'] ? date('d/m/Y', strtotime($aluno['data_nascimento'])) : '---'; ?></td>
                                <td><?php echo htmlspecialchars($aluno['bi'] ?: '---'); ?></td>
                                <td><?php echo htmlspecialchars($aluno['pai_nome'] ?: '---'); ?></td>
                                <td><?php echo htmlspecialchars($aluno['mae_nome'] ?: '---'); ?></td>
                                <?php else: ?>
                                <td class="text-center">
                                    <?php echo $aluno['genero'] == 'masculino' ? 'Masculino' : ($aluno['genero'] == 'feminino' ? 'Feminino' : '---'); ?>
                                </td>
                                <td><?php echo $aluno['data_nascimento'] ? date('d/m/Y', strtotime($aluno['data_nascimento'])) : '---'; ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-3 text-muted small">
                    <i class="fas fa-info-circle"></i> Total de registros: <?php echo count($alunos); ?> alunos
                </div>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum aluno encontrado nesta turma.
            </div>
        <?php else: ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma para visualizar a lista de alunos.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu Toggle para Mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }
    </script>
</body>
</html>