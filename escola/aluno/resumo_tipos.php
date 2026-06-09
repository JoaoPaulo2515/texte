<?php
// escola/aluno/financeiro/resumo_tipos.php - Resumo por Tipo de Pagamento

require_once __DIR__ . '/../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Definir título da página
$titulo_pagina = 'Resumo por Tipo de Pagamento';

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Filtros
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$periodo_filtro = isset($_GET['periodo']) ? $_GET['periodo'] : 'ano';

// ==============================================
// BUSCAR TIPOS DE PAGAMENTO
// ==============================================
$sql_tipos = "SELECT DISTINCT tipo_pagamento FROM pagamentos 
              WHERE assinatura_id = :aluno_id AND escola_id = :escola_id 
              AND tipo_pagamento IS NOT NULL AND tipo_pagamento != ''
              ORDER BY tipo_pagamento ASC";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$tipos_disponiveis = $stmt_tipos->fetchAll(PDO::FETCH_COLUMN, 0);

// ==============================================
// RESUMO POR TIPO DE PAGAMENTO
// ==============================================
$sql_resumo = "SELECT 
                    tipo_pagamento,
                    COUNT(*) as quantidade,
                    SUM(valor) as total,
                    MIN(valor) as menor_valor,
                    MAX(valor) as maior_valor,
                    AVG(valor) as media_valor,
                    COUNT(DISTINCT MONTH(data_pagamento)) as meses_distintos
               FROM pagamentos 
               WHERE assinatura_id = :aluno_id 
               AND escola_id = :escola_id 
               AND status IN ('pago', 'confirmado')";

if ($ano_filtro > 0) {
    $sql_resumo .= " AND YEAR(data_pagamento) = :ano";
}
if ($status_filtro != 'todos') {
    $sql_resumo .= " AND status = :status";
}
if ($periodo_filtro == 'mes') {
    $sql_resumo .= " AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())";
} elseif ($periodo_filtro == 'trimestre') {
    $sql_resumo .= " AND QUARTER(data_pagamento) = QUARTER(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())";
} elseif ($periodo_filtro == 'semestre') {
    $sql_resumo .= " AND (MONTH(data_pagamento) BETWEEN 1 AND 6 OR MONTH(data_pagamento) BETWEEN 7 AND 12) 
                     AND YEAR(data_pagamento) = YEAR(CURDATE())";
}

$sql_resumo .= " GROUP BY tipo_pagamento
                 ORDER BY total DESC";

$stmt_resumo = $conn->prepare($sql_resumo);
$params = [':aluno_id' => $aluno_id, ':escola_id' => $escola_id];
if ($ano_filtro > 0) $params[':ano'] = $ano_filtro;
if ($status_filtro != 'todos') $params[':status'] = $status_filtro;
$stmt_resumo->execute($params);
$resumo_tipos = $stmt_resumo->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// DETALHAMENTO POR TIPO (últimos pagamentos)
// ==============================================
$detalhes_por_tipo = [];
foreach ($tipos_disponiveis as $tipo) {
    $sql_detalhes = "SELECT id, valor, data_pagamento, metodo_pagamento, numero_fatura, referente, status
                     FROM pagamentos 
                     WHERE assinatura_id = :aluno_id 
                     AND escola_id = :escola_id 
                     AND tipo_pagamento = :tipo
                     AND status IN ('pago', 'confirmado')";
    if ($ano_filtro > 0) {
        $sql_detalhes .= " AND YEAR(data_pagamento) = :ano";
    }
    $sql_detalhes .= " ORDER BY data_pagamento DESC LIMIT 5";
    
    $stmt_detalhes = $conn->prepare($sql_detalhes);
    $params_detalhes = [
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id,
        ':tipo' => $tipo
    ];
    if ($ano_filtro > 0) $params_detalhes[':ano'] = $ano_filtro;
    $stmt_detalhes->execute($params_detalhes);
    $detalhes_por_tipo[$tipo] = $stmt_detalhes->fetchAll(PDO::FETCH_ASSOC);
}

// ==============================================
// ESTATÍSTICAS GERAIS
// ==============================================
$total_geral = array_sum(array_column($resumo_tipos, 'total'));
$total_quantidade = array_sum(array_column($resumo_tipos, 'quantidade'));
$media_geral = $total_quantidade > 0 ? $total_geral / $total_quantidade : 0;
$total_tipos = count($resumo_tipos);

// Maior e menor valor por tipo
$maior_valor_tipo = !empty($resumo_tipos) ? max(array_column($resumo_tipos, 'total')) : 0;
$menor_valor_tipo = !empty($resumo_tipos) ? min(array_column($resumo_tipos, 'total')) : 0;

// ==============================================
// BUSCAR ANOS DISPONÍVEIS
// ==============================================
$sql_anos = "SELECT DISTINCT YEAR(data_pagamento) as ano 
             FROM pagamentos 
             WHERE assinatura_id = :aluno_id AND escola_id = :escola_id 
             ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getTipoPagamentoLabel($tipo) {
    $tipos = [
        'mensalidade' => 'Mensalidade',
        'matricula' => 'Matrícula',
        'material' => 'Material Escolar',
        'atividade' => 'Atividade Extracurricular',
        'taxa' => 'Taxa Escolar',
        'laboratorio' => 'Laboratório',
        'campo' => 'Saída de Campo',
        'uniforme' => 'Uniforme',
        'biblioteca' => 'Biblioteca',
        'informatica' => 'Informática',
        'desporto' => 'Desporto',
        'outro' => 'Outro'
    ];
    return $tipos[$tipo] ?? ucfirst(str_replace('_', ' ', $tipo));
}

function getIconeTipo($tipo) {
    $icones = [
        'mensalidade' => '<i class="fas fa-calendar-dollar"></i>',
        'matricula' => '<i class="fas fa-id-card"></i>',
        'material' => '<i class="fas fa-book"></i>',
        'atividade' => '<i class="fas fa-futbol"></i>',
        'taxa' => '<i class="fas fa-receipt"></i>',
        'laboratorio' => '<i class="fas fa-flask"></i>',
        'campo' => '<i class="fas fa-hiking"></i>',
        'uniforme' => '<i class="fas fa-tshirt"></i>',
        'biblioteca' => '<i class="fas fa-library"></i>',
        'informatica' => '<i class="fas fa-laptop"></i>',
        'desporto' => '<i class="fas fa-medal"></i>',
        'outro' => '<i class="fas fa-ellipsis-h"></i>'
    ];
    return $icones[$tipo] ?? '<i class="fas fa-money-bill-wave"></i>';
}

function getCorTipo($tipo) {
    $cores = [
        'mensalidade' => '#006B3E',
        'matricula' => '#1A2A6C',
        'material' => '#28a745',
        'atividade' => '#17a2b8',
        'taxa' => '#ffc107',
        'laboratorio' => '#6f42c1',
        'campo' => '#fd7e14',
        'uniforme' => '#e83e8c',
        'biblioteca' => '#20c997',
        'informatica' => '#007bff',
        'desporto' => '#dc3545',
        'outro' => '#6c757d'
    ];
    return $cores[$tipo] ?? '#6c757d';
}

function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getPorcentagem($valor, $total) {
    if ($total == 0) return 0;
    return round(($valor / $total) * 100, 1);
}


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .tipo-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .tipo-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            cursor: pointer;
            background: #f8f9fa;
        }
        .tipo-body {
            padding: 20px;
            display: none;
        }
        .tipo-body.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        .toggle-icon {
            transition: transform 0.3s;
        }
        .toggle-icon.rotated {
            transform: rotate(180deg);
        }
        
        .progress-bar-custom {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-ajuda.show {
            display: flex;
        }
        .modal-ajuda-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-ajuda-body {
            padding: 20px;
        }
        .modal-ajuda-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        .ajuda-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .ajuda-item:last-child {
            border-bottom: none;
        }
        .ajuda-titulo {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 8px;
        }
        .ajuda-texto {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .ajuda-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            color: #006B3E;
            font-weight: bold;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno, .tipo-header { display: none; }
            .tipo-body { display: block !important; }
        }
        
        .badge-tipo {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_aluno.php'; ?>
    
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Resumo por Tipo de Pagamento</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe um resumo detalhado de todos os seus pagamentos agrupados por tipo.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Tipos de Pagamento</div>
                <div class="ajuda-texto">Mensalidade, Matrícula, Material, Atividades, Taxas, etc.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Gráficos</div>
                <div class="ajuda-texto">Visualize a distribuição dos seus pagamentos por tipo e por período.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Filtros</div>
                <div class="ajuda-texto">Filtre por ano, status e período para análise específica.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-pie"></i> Resumo por Tipo de Pagamento</h4>
            <p class="text-muted mb-0">Análise detalhada dos seus pagamentos por categoria</p>
        </div>
        <div>
            <button class="btn btn-secondary" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno_matricula; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-tags"></i> Tipos</small>
                    <h6 class="mb-0"><?php echo $total_tipos; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo formatarMoeda($total_geral); ?> KZ</div>
                <div class="stat-label">Total Pago</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_quantidade; ?></div>
                <div class="stat-label">Total de Pagamentos</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo formatarMoeda($media_geral); ?> KZ</div>
                <div class="stat-label">Ticket Médio</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $total_tipos; ?></div>
                <div class="stat-label">Categorias</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os anos</option>
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano; ?>" <?php echo $ano_filtro == $ano ? 'selected' : ''; ?>><?php echo $ano; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="pago" <?php echo $status_filtro == 'pago' ? 'selected' : ''; ?>>Pagos</option>
                        <option value="confirmado" <?php echo $status_filtro == 'confirmado' ? 'selected' : ''; ?>>Confirmados</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Período</label>
                    <select name="periodo" class="form-select" onchange="this.form.submit()">
                        <option value="ano" <?php echo $periodo_filtro == 'ano' ? 'selected' : ''; ?>>Ano completo</option>
                        <option value="mes" <?php echo $periodo_filtro == 'mes' ? 'selected' : ''; ?>>Mês atual</option>
                        <option value="trimestre" <?php echo $periodo_filtro == 'trimestre' ? 'selected' : ''; ?>>Trimestre atual</option>
                        <option value="semestre" <?php echo $periodo_filtro == 'semestre' ? 'selected' : ''; ?>>Semestre atual</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <a href="resumo_tipos.php" class="btn btn-outline-secondary w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Gráficos -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold"><i class="fas fa-chart-pie"></i> Distribuição por Tipo</div>
                <div class="card-body">
                    <canvas id="graficoPizza" height="250"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold"><i class="fas fa-chart-bar"></i> Valores por Tipo</div>
                <div class="card-body">
                    <canvas id="graficoBarras" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabela Resumo -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-table"></i> Resumo por Tipo de Pagamento</div>
        <div class="card-body">
            <?php if (empty($resumo_tipos)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhum pagamento encontrado para os filtros selecionados.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Tipo</th>
                                <th>Quantidade</th>
                                <th>Valor Total</th>
                                <th>% do Total</th>
                                <th>Ticket Médio</th>
                                <th>Menor Valor</th>
                                <th>Maior Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resumo_tipos as $res): 
                                $porcentagem = getPorcentagem($res['total'], $total_geral);
                                $cor = getCorTipo($res['tipo_pagamento']);
                            ?>
                            <tr>
                                <td>>
                                    <span class="badge-tipo" style="background: <?php echo $cor; ?>20; color: <?php echo $cor; ?>;">
                                        <?php echo getIconeTipo($res['tipo_pagamento']); ?> <?php echo getTipoPagamentoLabel($res['tipo_pagamento']); ?>
                                    </span>
                                </td>
                                <td><?php echo $res['quantidade']; ?></td>
                                <td class="text-end fw-bold text-success"><?php echo formatarMoeda($res['total']); ?> KZ</td>
                                <td class="text-end">
                                    <?php echo $porcentagem; ?>%
                                    <div class="progress-bar-custom mt-1">
                                        <div class="progress-fill" style="width: <?php echo $porcentagem; ?>%; background: <?php echo $cor; ?>;"></div>
                                    </div>
                                </td>
                                <td class="text-end"><?php echo formatarMoeda($res['media_valor']); ?> KZ</td>
                                <td class="text-end"><?php echo formatarMoeda($res['menor_valor']); ?> KZ</td>
                                <td class="text-end"><?php echo formatarMoeda($res['maior_valor']); ?> KZ</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary fw-bold">
                            <tr>
                                <td>TOTAL</td>
                                <td><?php echo $total_quantidade; ?></td>
                                <td class="text-end"><?php echo formatarMoeda($total_geral); ?> KZ</td>
                                <td class="text-end">100%</td>
                                <td class="text-end"><?php echo formatarMoeda($media_geral); ?> KZ</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Detalhamento por Tipo (expansível) -->
<?php if (!empty($tipos_disponiveis)): ?>
<div class="card border-0 shadow-sm mt-4 fade-in">
    <div class="card-header bg-white fw-bold"><i class="fas fa-list-alt"></i> Detalhamento por Tipo</div>
    <div class="card-body">
        <?php foreach ($tipos_disponiveis as $tipo):
            $cor = getCorTipo($tipo);
            $icone = getIconeTipo($tipo);
            $label = getTipoPagamentoLabel($tipo);
            
            // Buscar detalhes por tipo
            $sql_detalhes = "SELECT id, valor, data_pagamento, metodo_pagamento, numero_fatura, referente, status
                             FROM pagamentos 
                             WHERE assinatura_id = :aluno_id 
                             AND escola_id = :escola_id 
                             AND tipo_pagamento = :tipo
                             AND status IN ('pago', 'confirmado')";
            if ($ano_filtro > 0) {
                $sql_detalhes .= " AND YEAR(data_pagamento) = :ano";
            }
            $sql_detalhes .= " ORDER BY data_pagamento DESC LIMIT 5";
            
            $stmt_detalhes = $conn->prepare($sql_detalhes);
            $params_detalhes = [
                ':aluno_id' => $aluno_id,
                ':escola_id' => $escola_id,
                ':tipo' => $tipo
            ];
            if ($ano_filtro > 0) $params_detalhes[':ano'] = $ano_filtro;
            $stmt_detalhes->execute($params_detalhes);
            $detalhes = $stmt_detalhes->fetchAll(PDO::FETCH_ASSOC);
            
            // CORREÇÃO: Calcular total com segurança
            $total_tipo = 0;
            if (!empty($detalhes)) {
                $valores = array_column($detalhes, 'valor');
                if (is_array($valores)) {
                    $total_tipo = array_sum($valores);
                }
            }
        ?>
        <div class="tipo-card" id="tipo-<?php echo $tipo; ?>">
            <div class="tipo-header" onclick="toggleTipo('<?php echo $tipo; ?>')">
                <div>
                    <span class="badge-tipo" style="background: <?php echo $cor; ?>20; color: <?php echo $cor; ?>;">
                        <?php echo $icone; ?> <?php echo $label; ?>
                    </span>
                    <span class="ms-2 text-muted">(<?php echo count($detalhes); ?> registros)</span>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="text-success fw-bold">Total: <?php echo formatarMoeda($total_tipo); ?> KZ</span>
                    <i class="fas fa-chevron-down toggle-icon" id="toggle-icon-<?php echo $tipo; ?>"></i>
                </div>
            </div>
            <div class="tipo-body" id="tipo-body-<?php echo $tipo; ?>">
                <?php if (empty($detalhes)): ?>
                    <p class="text-muted text-center">Nenhum detalhe encontrado para este tipo.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Data</th><th>Referente</th><th>Nº Fatura</th><th>Método</th><th class="text-end">Valor</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detalhes as $det): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($det['data_pagamento'])); ?></td>
                                    <td><?php echo htmlspecialchars($det['referente']); ?></td>
                                    <td><?php echo $det['numero_fatura'] ?? '-'; ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $det['metodo_pagamento'] ?? '-')); ?></td>
                                    <td class="text-end text-success fw-bold"><?php echo formatarMoeda($det['valor']); ?> KZ</td>
                                    <td><span class="badge bg-success"><?php echo ucfirst($det['status']); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">TOTAL DO TIPO:</td>
                                    <td class="text-end text-success"><?php echo formatarMoeda($total_tipo); ?> KZ</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
    
    // Função para expandir/colapsar tipo
    function toggleTipo(tipo) {
        const body = document.getElementById('tipo-body-' + tipo);
        const icon = document.getElementById('toggle-icon-' + tipo);
        body.classList.toggle('show');
        icon.classList.toggle('rotated');
    }
    
    // Dados para os gráficos
    const tipos = <?php echo json_encode(array_map(function($item) { return getTipoPagamentoLabel($item['tipo_pagamento']); }, $resumo_tipos)); ?>;
    const valores = <?php echo json_encode(array_column($resumo_tipos, 'total')); ?>;
    const cores = <?php echo json_encode(array_map(function($item) { return getCorTipo($item['tipo_pagamento']); }, $resumo_tipos)); ?>;
    
    // Gráfico de Pizza
    if (tipos.length > 0) {
        new Chart(document.getElementById('graficoPizza'), {
            type: 'pie',
            data: {
                labels: tipos,
                datasets: [{
                    data: valores,
                    backgroundColor: cores,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = valores.reduce((a, b) => a + b, 0);
                                const percent = ((value / total) * 100).toFixed(1);
                                return `${label}: ${value.toLocaleString('pt-AO')} KZ (${percent}%)`;
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de Barras
        new Chart(document.getElementById('graficoBarras'), {
            type: 'bar',
            data: {
                labels: tipos,
                datasets: [{
                    label: 'Valor Total (KZ)',
                    data: valores,
                    backgroundColor: cores,
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('pt-AO') + ' KZ';
                            }
                        }
                    },
                    x: {
                        ticks: { autoSkip: false, rotation: 45, font: { size: 10 } }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Total: ' + context.raw.toLocaleString('pt-AO') + ' KZ';
                            }
                        }
                    }
                }
            }
        });
    }
</script>
</body>
</html>