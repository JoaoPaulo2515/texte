<?php
// aluno/financeiro/projecoes.php - Projeções Financeiras

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
$sql_aluno = "SELECT e.nome, e.matricula, e.email,
                     tur.nome as turma_nome, tur.ano as turma_ano,
                     es.nome as escola_nome
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas tur ON tur.id = m.turma_id
              LEFT JOIN escolas es ON es.id = e.escola_id
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS HISTÓRICOS
// ============================================

// Buscar histórico de pagamentos dos últimos 12 meses
$sql_historico = "SELECT 
                      DATE_FORMAT(p.data_pagamento, '%Y-%m') as mes,
                      SUM(p.valor) as total_pago,
                      COUNT(*) as quantidade
                  FROM pagamentos p
                  WHERE p.assinatura_id = :aluno_id 
                  AND p.status = 'confirmado'
                  AND p.escola_id = :escola_id
                  AND p.data_pagamento >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  GROUP BY DATE_FORMAT(p.data_pagamento, '%Y-%m')
                  ORDER BY mes ASC";
$stmt_historico = $conn->prepare($sql_historico);
$stmt_historico->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$historico = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALCULAR MÉDIAS
// ============================================

$media_mensal = 0;
if (!empty($historico)) {
    $soma_pagos = array_sum(array_column($historico, 'total_pago'));
    $media_mensal = $soma_pagos / count($historico);
}

// ============================================
// BUSCAR COMPROMISSOS FUTUROS
// ============================================

// Mensalidades previstas para os próximos 6 meses
$meses_proximos = [];
$ano_atual = date('Y');
$mes_atual = date('n');

for ($i = 0; $i < 6; $i++) {
    $mes = $mes_atual + $i;
    $ano = $ano_atual;
    if ($mes > 12) {
        $mes -= 12;
        $ano++;
    }
    
    // Buscar valor da mensalidade para este mês
    $sql_valor = "SELECT valor_total FROM mensalidades 
                  WHERE ano_referencia = :ano AND mes_referencia = :mes 
                  AND escola_id = :escola_id LIMIT 1";
    $stmt_valor = $conn->prepare($sql_valor);
    $stmt_valor->execute([
        ':ano' => $ano,
        ':mes' => $mes,
        ':escola_id' => $escola_id
    ]);
    $valor_mensalidade = $stmt_valor->fetch(PDO::FETCH_ASSOC);
    
    // Verificar se já foi paga
    $sql_pago = "SELECT COUNT(*) as pago FROM pagamentos p
                 JOIN mensalidades m ON m.aluno_id = p.assinatura_id
                 WHERE p.assinatura_id = :aluno_id 
                 AND m.ano_referencia = :ano 
                 AND m.mes_referencia = :mes
                 AND p.status = 'confirmado'";
    $stmt_pago = $conn->prepare($sql_pago);
    $stmt_pago->execute([
        ':aluno_id' => $aluno_id,
        ':ano' => $ano,
        ':mes' => $mes
    ]);
    $ja_pago = $stmt_pago->fetch(PDO::FETCH_ASSOC);
    
    $meses_proximos[] = [
        'ano' => $ano,
        'mes' => $mes,
        'mes_nome' => getNomeMes($mes),
        'valor' => $valor_mensalidade['valor'] ?? 5000,
        'status' => $ja_pago['pago'] > 0 ? 'pago' : 'pendente',
        'data_vencimento' => date("$ano-$mes-10")
    ];
}

// ============================================
// BUSCAR OUTROS PAGAMENTOS PREVISTOS
// ============================================

// Matrícula para próximo ano
$proximo_ano = date('Y') + 1;
$sql_matricula = "SELECT valor FROM pagamentos 
                  WHERE tipo_pagamento = 'matricula' 
                  AND escola_id = :escola_id LIMIT 1";
$stmt_matricula = $conn->prepare($sql_matricula);
$stmt_matricula->execute([':escola_id' => $escola_id]);
$valor_matricula = $stmt_matricula->fetch(PDO::FETCH_ASSOC);

// Material didático (semestral)
$valor_material = 3500;

// Certificados (se aplicável)
$valor_certificado = 2500;

// ============================================
// CALCULAR PROJEÇÕES
// ============================================

$total_proximo_ano = 0;
$total_proximo_semestre = 0;
$total_proximo_trimestre = 0;

foreach ($meses_proximos as $indice => $mes) {
    if ($mes['status'] != 'pago') {
        $valor = $mes['valor'];
        
        // Próximos 3 meses
        if ($indice < 3) {
            $total_proximo_trimestre += $valor;
        }
        // Próximos 6 meses
        if ($indice < 6) {
            $total_proximo_semestre += $valor;
        }
        $total_proximo_ano += $valor;
    }
}

// Adicionar matrícula se for início do ano
if ($mes_atual >= 10) {
    $total_proximo_ano += ($valor_matricula['valor'] ?? 7500);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getNomeMes($mes) {
    $nomes = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $nomes[$mes];
}

function getStatusClass($status) {
    if ($status == 'pago') {
        return 'bg-success text-white';
    }
    return 'bg-warning text-dark';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projeções Financeiras | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.85rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .previsao-item {
            transition: all 0.3s;
        }
        .previsao-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .resumo-card {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .dica-card {
            background: #e8f5e9;
            border-left: 4px solid #006B3E;
            border-radius: 10px;
            padding: 15px;
        }
        
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <?php include '../includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-chart-line"></i> Projeções Financeiras</h2>
                <p class="text-muted">Planeje seus gastos futuros com base no histórico e compromissos</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo formatarMoeda($media_mensal); ?></div>
                    <div class="stat-label"><i class="fas fa-chart-simple"></i> Média Mensal</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo formatarMoeda($total_proximo_trimestre); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-week"></i> Próximos 3 meses</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo formatarMoeda($total_proximo_semestre); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-alt"></i> Próximos 6 meses</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($total_proximo_ano); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-year"></i> Próximo ano letivo</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Gráficos -->
            <div class="col-lg-7">
                <!-- Gráfico de Histórico -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Histórico de Pagamentos (12 meses)</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="historicoChart" height="200"></canvas>
                    </div>
                </div>
                
                <!-- Gráfico de Projeção -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Projeção de Gastos</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="projecaoChart" height="200"></canvas>
                    </div>
                </div>
                
                <!-- Previsão Mensal Detalhada -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Previsão Mensal Detalhada</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Mês/Ano</th>
                                        <th>Vencimento</th>
                                        <th class="text-end">Valor</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($meses_proximos as $mes): ?>
                                    <tr class="previsao-item">
                                        <td><?php echo $mes['mes_nome'] . '/' . $mes['ano']; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($mes['data_vencimento'])); ?>Ne
                                        <td class="text-end fw-bold"><?php echo formatarMoeda($mes['valor']); ?>Ne
                                        <td>
                                            <span class="badge <?php echo getStatusClass($mes['status']); ?>">
                                                <?php echo $mes['status'] == 'pago' ? 'Pago' : 'Pendente'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td colspan="2" class="text-end">TOTAL (próximos 6 meses):</td>
                                        <td class="text-end"><?php echo formatarMoeda($total_proximo_semestre); ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Resumo e Planejamento -->
            <div class="col-lg-5">
                <!-- Resumo Financeiro -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calculator"></i> Planejamento Financeiro</h5>
                    </div>
                    <div class="card-body">
                        <div class="resumo-card">
                            <div class="row">
                                <div class="col-6">
                                    <small>Média mensal</small>
                                    <h4><?php echo formatarMoeda($media_mensal); ?></h4>
                                </div>
                                <div class="col-6">
                                    <small>Meta mensal sugerida</small>
                                    <h4><?php echo formatarMoeda($media_mensal * 1.1); ?></h4>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <h6><i class="fas fa-piggy-bank"></i> Economia sugerida</h6>
                            <p class="small text-muted">Reserve mensalmente para cobrir despesas futuras:</p>
                            <div class="progress mb-2" style="height: 25px;">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: 70%" aria-valuenow="70" aria-valuemin="0" aria-valuemax="100">
                                    Mensalidade: 70%
                                </div>
                                <div class="progress-bar bg-warning" role="progressbar" 
                                     style="width: 20%" aria-valuenow="20" aria-valuemin="0" aria-valuemax="100">
                                    Matrícula: 20%
                                </div>
                                <div class="progress-bar bg-info" role="progressbar" 
                                     style="width: 10%" aria-valuenow="10" aria-valuemin="0" aria-valuemax="100">
                                    Material: 10%
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6><i class="fas fa-calendar-week"></i> Datas importantes</h6>
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="fas fa-calendar-day text-danger"></i> Vencimento mensal: Dia 10</li>
                            <li class="mb-2"><i class="fas fa-calendar-day text-warning"></i> Matrícula: Outubro - Novembro</li>
                            <li class="mb-2"><i class="fas fa-calendar-day text-info"></i> Material didático: Fevereiro e Agosto</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Gastos Previstos por Tipo -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Distribuição de Gastos</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="distribuicaoChart" height="200"></canvas>
                        <div class="mt-3">
                            <div class="d-flex justify-content-between">
                                <span><i class="fas fa-circle text-success"></i> Mensalidades</span>
                                <span><?php echo formatarMoeda($total_proximo_semestre); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span><i class="fas fa-circle text-warning"></i> Matrícula</span>
                                <span><?php echo formatarMoeda($valor_matricula['valor'] ?? 7500); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span><i class="fas fa-circle text-info"></i> Material Didático</span>
                                <span><?php echo formatarMoeda($valor_material); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span><i class="fas fa-circle text-secondary"></i> Certificados</span>
                                <span><?php echo formatarMoeda($valor_certificado); ?></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between fw-bold">
                                <span>TOTAL ANUAL PREVISTO</span>
                                <span><?php echo formatarMoeda($total_proximo_ano + ($valor_matricula['valor'] ?? 7500) + $valor_material + $valor_certificado); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Dicas de Planejamento -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Dicas de Planejamento</h5>
                    </div>
                    <div class="card-body">
                        <div class="dica-card">
                            <i class="fas fa-piggy-bank fa-2x text-success mb-2"></i>
                            <h6>Reserve com antecedência</h6>
                            <p class="small">Separe mensalmente um valor para a matrícula do próximo ano.</p>
                        </div>
                        <div class="dica-card mt-2">
                            <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                            <h6>Evite multas</h6>
                            <p class="small">Pague até o vencimento para evitar juros e multas.</p>
                        </div>
                        <div class="dica-card mt-2">
                            <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                            <h6>Planeje os meses de maior gasto</h6>
                            <p class="small">Janeiro e Fevereiro geralmente têm matrícula + material.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Gráfico de Histórico
        const ctxHistorico = document.getElementById('historicoChart')?.getContext('2d');
        if (ctxHistorico) {
            const meses = [<?php 
                $meses_labels = [];
                foreach ($historico as $h) {
                    $meses_labels[] = "'" . date('M/Y', strtotime($h['mes'] . '-01')) . "'";
                }
                echo implode(',', $meses_labels);
            ?>];
            const valores = [<?php 
                $valores = [];
                foreach ($historico as $h) {
                    $valores[] = $h['total_pago'];
                }
                echo implode(',', $valores);
            ?>];
            
            new Chart(ctxHistorico, {
                type: 'bar',
                data: {
                    labels: meses,
                    datasets: [{
                        label: 'Valor Pago (Kz)',
                        data: valores,
                        backgroundColor: 'rgba(0, 107, 62, 0.7)',
                        borderColor: '#006B3E',
                        borderWidth: 1,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Valor: ' + context.raw.toLocaleString('pt-AO') + ' Kz';
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
        }
        
        // Gráfico de Projeção
        const ctxProjecao = document.getElementById('projecaoChart')?.getContext('2d');
        if (ctxProjecao) {
            const mesesProj = [<?php 
                $meses_proj = [];
                foreach ($meses_proximos as $m) {
                    $meses_proj[] = "'" . substr($m['mes_nome'], 0, 3) . "/" . substr($m['ano'], -2) . "'";
                }
                echo implode(',', $meses_proj);
            ?>];
            const valoresProj = [<?php 
                $valores_proj = [];
                foreach ($meses_proximos as $m) {
                    $valores_proj[] = $m['valor'];
                }
                echo implode(',', $valores_proj);
            ?>];
            
            new Chart(ctxProjecao, {
                type: 'line',
                data: {
                    labels: mesesProj,
                    datasets: [{
                        label: 'Previsão de Gastos (Kz)',
                        data: valoresProj,
                        borderColor: '#006B3E',
                        backgroundColor: 'rgba(0, 107, 62, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#006B3E',
                        pointBorderColor: '#fff',
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Previsão: ' + context.raw.toLocaleString('pt-AO') + ' Kz';
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
        }
        
        // Gráfico de Distribuição
        const ctxDistribuicao = document.getElementById('distribuicaoChart')?.getContext('2d');
        if (ctxDistribuicao) {
            new Chart(ctxDistribuicao, {
                type: 'doughnut',
                data: {
                    labels: ['Mensalidades', 'Matrícula', 'Material', 'Certificados'],
                    datasets: [{
                        data: [
                            <?php echo $total_proximo_semestre; ?>, 
                            <?php echo $valor_matricula['valor'] ?? 7500; ?>, 
                            <?php echo $valor_material; ?>, 
                            <?php echo $valor_certificado; ?>
                        ],
                        backgroundColor: ['#28a745', '#ffc107', '#17a2b8', '#6c757d'],
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
                                    return context.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz';
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