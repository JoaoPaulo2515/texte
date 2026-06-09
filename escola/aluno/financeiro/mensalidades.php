<?php
// aluno/financeiro/mensalidades.php - Mensalidades do Aluno

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// ============================================
// FILTROS
// ============================================
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : 0;
$exportar = isset($_GET['exportar']) ? $_GET['exportar'] : '';

// Buscar anos disponíveis da tabela ano_letivo
$sql_anos = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Buscar mensalidades com filtro
$sql_mensalidades = "SELECT * FROM mensalidades WHERE aluno_id = :aluno_id";
if ($ano_filtro > 0) {
    $sql_mensalidades .= " AND ano_referencia = :ano";
}
$sql_mensalidades .= " ORDER BY ano_referencia DESC, mes_referencia ASC";

$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$params = [':aluno_id' => $aluno_id];
if ($ano_filtro > 0) {
    $params[':ano'] = $ano_filtro;
}
$stmt_mensalidades->execute($params);
$mensalidades = $stmt_mensalidades->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================

// Totais gerais
$total_geral = array_sum(array_column($mensalidades, 'valor_total'));
$total_pago = array_sum(array_column($mensalidades, 'valor_pago'));
$total_devedor = $total_geral - $total_pago;
$total_mensalidades = count($mensalidades);
$total_pagas = count(array_filter($mensalidades, function($m) { return $m['status'] == 'pago'; }));
$total_pendentes = count(array_filter($mensalidades, function($m) { return $m['status'] == 'pendente'; }));
$total_parciais = count(array_filter($mensalidades, function($m) { return $m['status'] == 'parcial'; }));
$total_atrasadas = count(array_filter($mensalidades, function($m) { return $m['status'] == 'atrasado'; }));

// Percentual de adimplência
$percentual_adimplencia = $total_geral > 0 ? ($total_pago / $total_geral) * 100 : 0;

// Estatísticas por ano
$estatisticas_por_ano = [];
foreach ($anos_letivos as $ano_letivo) {
    $ano = $ano_letivo['ano'];
    $mensalidades_ano = array_filter($mensalidades, function($m) use ($ano) { return $m['ano_referencia'] == $ano; });
    $total_ano = array_sum(array_column($mensalidades_ano, 'valor_total'));
    $pago_ano = array_sum(array_column($mensalidades_ano, 'valor_pago'));
    $estatisticas_por_ano[] = [
        'ano' => $ano,
        'ano_id' => $ano_letivo['id'],
        'total' => $total_ano,
        'pago' => $pago_ano,
        'devedor' => $total_ano - $pago_ano,
        'quantidade' => count($mensalidades_ano),
        'pagas' => count(array_filter($mensalidades_ano, function($m) { return $m['status'] == 'pago'; })),
        'percentual' => $total_ano > 0 ? ($pago_ano / $total_ano) * 100 : 0
    ];
}

// ============================================
// EXPORTAÇÃO
// ============================================
if ($exportar == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mensalidades_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Mês/Ano', 'Valor Total', 'Valor Pago', 'Saldo Devedor', 'Data Vencimento', 'Status']);
    
    foreach ($mensalidades as $m) {
        fputcsv($output, [
            getMesNome($m['mes_referencia']) . '/' . $m['ano_referencia'],
            number_format($m['valor_total'], 2, ',', '.'),
            number_format($m['valor_pago'], 2, ',', '.'),
            number_format($m['valor_total'] - $m['valor_pago'], 2, ',', '.'),
            date('d/m/Y', strtotime($m['data_vencimento'])),
            $m['status']
        ]);
    }
    fclose($output);
    exit;
}

if ($exportar == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="mensalidades_' . date('Ymd') . '.xls"');
    echo '<table border="1">';
    echo '<tr><th>Mês/Ano</th><th>Valor Total</th><th>Valor Pago</th><th>Saldo Devedor</th><th>Data Vencimento</th><th>Status</th></tr>';
    foreach ($mensalidades as $m) {
        echo '<tr>';
        echo '<td>' . getMesNome($m['mes_referencia']) . '/' . $m['ano_referencia'] . '</td>';
        echo '<td>' . number_format($m['valor_total'], 2, ',', '.') . '</td>';
        echo '<td>' . number_format($m['valor_pago'], 2, ',', '.') . '</td>';
        echo '<td>' . number_format($m['valor_total'] - $m['valor_pago'], 2, ',', '.') . '</td>';
        echo '<td>' . date('d/m/Y', strtotime($m['data_vencimento'])) . '</td>';
        echo '<td>' . $m['status'] . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

// Funções auxiliares
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pago': return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
        case 'parcial': return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Parcial</span>';
        case 'pendente': return '<span class="badge bg-secondary"><i class="fas fa-hourglass-half"></i> Pendente</span>';
        case 'atrasado': return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Atrasado</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getMesNome($mes) {
    $meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
              5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
              9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    return $meses[$mes] ?? '-';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensalidades | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        
        .info-aluno {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-left: 4px solid #006B3E;
            border-radius: 10px;
            padding: 15px;
        }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .btn-export { background: #17a2b8; color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; }
        .btn-export:hover { background: #138496; }
        
        .progress-bar-custom { height: 8px; border-radius: 4px; }
        
        @media print {
            .no-print { display: none !important; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .main-content { margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
     <?php include '../includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-calendar-dollar"></i> Minhas Mensalidades</h2>
                <p class="text-muted">Acompanhe o status das suas mensalidades</p>
            </div>
            <div class="no-print">
                <div class="btn-group">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download"></i> Exportar
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['exportar' => 'csv'])); ?>"><i class="fas fa-file-csv"></i> Exportar CSV</a></li>
                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['exportar' => 'excel'])); ?>"><i class="fas fa-file-excel"></i> Exportar Excel</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" onclick="window.print();"><i class="fas fa-print"></i> Imprimir</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Informações do Aluno (como na página de frequência) -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="info-aluno">
                    <div class="row">
                        <div class="col-md-4">
                            <i class="fas fa-user-graduate"></i>
                            <strong>Aluno:</strong> <?php echo htmlspecialchars($aluno['nome']); ?>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-id-card"></i>
                            <strong>Matrícula:</strong> <?php echo $aluno['matricula']; ?>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-users"></i>
                            <strong>Turma:</strong> <?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?>
                        </div>
                        <div class="col-md-2">
                            <i class="fas fa-chart-line"></i>
                            <strong>Adimplência:</strong> <?php echo number_format($percentual_adimplencia, 1); ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo number_format($total_mensalidades); ?></div>
                    <div class="stat-label"><i class="fas fa-file-invoice"></i> Total de Mensalidades</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($total_pago); ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle"></i> Total Pago</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($total_devedor); ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Saldo Devedor</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?php echo $percentual_adimplencia >= 70 ? 'text-success' : ($percentual_adimplencia >= 40 ? 'text-warning' : 'text-danger'); ?>">
                        <?php echo number_format($percentual_adimplencia, 1); ?>%
                    </div>
                    <div class="stat-label"><i class="fas fa-chart-line"></i> Adimplência</div>
                    <div class="progress mt-2 progress-bar-custom">
                        <div class="progress-bar <?php echo $percentual_adimplencia >= 70 ? 'bg-success' : ($percentual_adimplencia >= 40 ? 'bg-warning' : 'bg-danger'); ?>" 
                             style="width: <?php echo $percentual_adimplencia; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Segunda linha de estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $total_pagas; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle"></i> Mensalidades Pagas</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_pendentes; ?></div>
                    <div class="stat-label"><i class="fas fa-clock"></i> Pendentes</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo $total_atrasadas; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Atrasadas</div>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Comparação por Ano - Altura reduzida -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Comparativo por Ano Letivo</h5>
            </div>
            <div class="card-body">
                <canvas id="graficoComparacao" height="100"></canvas>
            </div>
        </div>
        
        <!-- Filtro por Ano -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Ano Letivo</label>
                        <select name="ano" class="form-select">
                            <option value="0">Todos os anos</option>
                            <?php foreach ($anos_letivos as $ano_letivo): ?>
                            <option value="<?php echo $ano_letivo['ano']; ?>" <?php echo $ano_filtro == $ano_letivo['ano'] ? 'selected' : ''; ?>>
                                <?php echo $ano_letivo['ano']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($ano_filtro > 0): ?>
                        <a href="mensalidades.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i> Limpar
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tabela de Mensalidades -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Histórico de Mensalidades</h5>
            </div>
            <div class="card-body">
                <?php if (empty($mensalidades)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma mensalidade encontrada.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Mês/Ano</th>
                                    <th class="text-end">Valor Total</th>
                                    <th class="text-end">Valor Pago</th>
                                    <th class="text-end">Saldo Devedor</th>
                                    <th>Vencimento</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mensalidades as $mensalidade): ?>
                                <tr>
                                    <td><strong><?php echo getMesNome($mensalidade['mes_referencia']) . '/' . $mensalidade['ano_referencia']; ?></strong></small></td>
                                    <td class="text-end"><?php echo formatarMoeda($mensalidade['valor_total']); ?></td>
                                    <td class="text-end text-success"><?php echo formatarMoeda($mensalidade['valor_pago']); ?></td>
                                    <td class="text-end text-danger fw-bold"><?php echo formatarMoeda($mensalidade['valor_total'] - $mensalidade['valor_pago']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($mensalidade['data_vencimento'])); ?></td>
                                    <td><?php echo getStatusBadge($mensalidade['status']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td>TOTAL:</td>
                                    <td class="text-end"><?php echo formatarMoeda($total_geral); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda($total_pago); ?></td>
                                    <td class="text-end text-danger"><?php echo formatarMoeda($total_devedor); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Estatísticas por Ano -->
        <?php if (!empty($estatisticas_por_ano) && $ano_filtro == 0): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Resumo por Ano Letivo</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Ano</th>
                                <th class="text-end">Total Faturado</th>
                                <th class="text-end">Total Pago</th>
                                <th class="text-end">Saldo Devedor</th>
                                <th class="text-center">Mensalidades</th>
                                <th class="text-center">Pagas</th>
                                <th class="text-center">Adimplência</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estatisticas_por_ano as $stats): ?>
                            <tr>
                                <td><strong><?php echo $stats['ano']; ?></strong></td>
                                <td class="text-end"><?php echo formatarMoeda($stats['total']); ?></td>
                                <td class="text-end text-success"><?php echo formatarMoeda($stats['pago']); ?></td>
                                <td class="text-end text-danger"><?php echo formatarMoeda($stats['devedor']); ?></td>
                                <td class="text-center"><?php echo $stats['quantidade']; ?></td>
                                <td class="text-center"><?php echo $stats['pagas']; ?></td>
                                <td class="text-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 6px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo $stats['percentual']; ?>%"></div>
                                        </div>
                                        <small><?php echo number_format($stats['percentual'], 1); ?>%</small>
                                    </div>
                                </td>
                            <tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Resumo da Situação Financeira -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Resumo da Situação Financeira</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="alert alert-info">
                            <i class="fas fa-lightbulb"></i>
                            <strong>Dica:</strong> Mantenha suas mensalidades em dia para evitar juros e multas.
                            Em caso de dificuldades, procure a secretaria da escola para negociar.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert <?php echo $total_devedor > 0 ? 'alert-warning' : 'alert-success'; ?>">
                            <i class="fas <?php echo $total_devedor > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>"></i>
                            <strong>Situação:</strong>
                            <?php if ($total_devedor == 0): ?>
                                <span class="text-success">Todas as mensalidades estão pagas! Parabéns!</span>
                            <?php elseif ($total_atrasadas > 0): ?>
                                <span class="text-danger">Você possui mensalidades em atraso. Regularize sua situação.</span>
                            <?php else: ?>
                                <span class="text-warning">Você possui pendências. Mantenha-se em dia.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Gráfico de Comparação por Ano - Altura reduzida
        const anos = <?php echo json_encode(array_column($estatisticas_por_ano, 'ano')); ?>;
        const totais = <?php echo json_encode(array_column($estatisticas_por_ano, 'total')); ?>;
        const pagos = <?php echo json_encode(array_column($estatisticas_por_ano, 'pago')); ?>;
        
        new Chart(document.getElementById('graficoComparacao'), {
            type: 'bar',
            data: {
                labels: anos,
                datasets: [
                    {
                        label: 'Total Faturado',
                        data: totais,
                        backgroundColor: 'rgba(0, 107, 62, 0.7)',
                        borderColor: '#006B3E',
                        borderWidth: 1,
                        borderRadius: 5
                    },
                    {
                        label: 'Total Pago',
                        data: pagos,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: '#28a745',
                        borderWidth: 1,
                        borderRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('pt-AO') + ' Kz';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>