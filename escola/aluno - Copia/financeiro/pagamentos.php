<?php
// aluno/financeiro/pagamentos.php - Histórico de Pagamentos do Aluno

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
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$exportar = isset($_GET['exportar']) ? $_GET['exportar'] : '';

// Buscar anos disponíveis dos pagamentos
$sql_anos = "SELECT DISTINCT YEAR(data_pagamento) as ano FROM pagamentos WHERE assinatura_id = :aluno_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

// Buscar pagamentos do aluno
$sql_pagamentos = "SELECT p.*, 
                          CASE 
                              WHEN p.tipo_pagamento = 'mensalidade' THEN CONCAT('Mensalidade - ', m.mes_referencia, '/', m.ano_referencia)
                              ELSE p.referente
                          END as descricao_completa
                   FROM pagamentos p
                   LEFT JOIN mensalidades m ON m.id = p.assinatura_id
                   WHERE p.assinatura_id = :aluno_id AND p.status = 'confirmado'";

if ($ano_filtro > 0) {
    $sql_pagamentos .= " AND YEAR(p.data_pagamento) = :ano";
}
if ($tipo_filtro != 'todos') {
    $sql_pagamentos .= " AND p.tipo_pagamento = :tipo";
}
$sql_pagamentos .= " ORDER BY p.data_pagamento DESC, p.id DESC";

$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$params = [':aluno_id' => $aluno_id];
if ($ano_filtro > 0) {
    $params[':ano'] = $ano_filtro;
}
if ($tipo_filtro != 'todos') {
    $params[':tipo'] = $tipo_filtro;
}
$stmt_pagamentos->execute($params);
$pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================

// Totais gerais
$total_pagamentos = count($pagamentos);
$total_valor = array_sum(array_column($pagamentos, 'valor'));

// Estatísticas por tipo de pagamento
$tipos_pagamento = [];
foreach ($pagamentos as $pg) {
    $tipo = $pg['tipo_pagamento'];
    if (!isset($tipos_pagamento[$tipo])) {
        $tipos_pagamento[$tipo] = [
            'quantidade' => 0,
            'total' => 0
        ];
    }
    $tipos_pagamento[$tipo]['quantidade']++;
    $tipos_pagamento[$tipo]['total'] += $pg['valor'];
}

// Estatísticas por ano
$estatisticas_por_ano = [];
foreach ($anos_disponiveis as $ano) {
    $pagamentos_ano = array_filter($pagamentos, function($p) use ($ano) {
        return date('Y', strtotime($p['data_pagamento'])) == $ano;
    });
    $estatisticas_por_ano[] = [
        'ano' => $ano,
        'quantidade' => count($pagamentos_ano),
        'total' => array_sum(array_column($pagamentos_ano, 'valor'))
    ];
}

// ============================================
// EXPORTAÇÃO
// ============================================
if ($exportar == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pagamentos_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Data', 'Tipo', 'Descrição', 'Valor', 'Forma de Pagamento', 'Fatura', 'Referência']);
    
    foreach ($pagamentos as $p) {
        fputcsv($output, [
            date('d/m/Y', strtotime($p['data_pagamento'])),
            ucfirst($p['tipo_pagamento']),
            $p['descricao_completa'],
            number_format($p['valor'], 2, ',', '.'),
            ucfirst($p['metodo_pagamento']),
            $p['numero_fatura'],
            $p['numero_referencia'] ?? '-'
        ]);
    }
    fclose($output);
    exit;
}

if ($exportar == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="pagamentos_' . date('Ymd') . '.xls"');
    echo '<table border="1">';
    echo '<tr><th>Data</th><th>Tipo</th><th>Descrição</th><th>Valor</th><th>Forma</th><th>Fatura</th><th>Referência</th></tr>';
    foreach ($pagamentos as $p) {
        echo '<tr>';
        echo '<td>' . date('d/m/Y', strtotime($p['data_pagamento'])) . '</td>';
        echo '<td>' . ucfirst($p['tipo_pagamento']) . '</td>';
        echo '<td>' . $p['descricao_completa'] . '</td>';
        echo '<td>' . number_format($p['valor'], 2, ',', '.') . '</td>';
        echo '<td>' . ucfirst($p['metodo_pagamento']) . '</td>';
        echo '<td>' . $p['numero_fatura'] . '</td>';
        echo '<td>' . ($p['numero_referencia'] ?? '-') . '</td>';
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

function getMetodoPagamentoIcone($metodo) {
    switch ($metodo) {
        case 'dinheiro': return '<i class="fas fa-money-bill-wave text-success"></i> Dinheiro';
        case 'transferencia': return '<i class="fas fa-university text-primary"></i> Transferência';
        case 'deposito': return '<i class="fas fa-money-bill text-info"></i> Depósito';
        case 'cheque': return '<i class="fas fa-check-circle text-warning"></i> Cheque';
        case 'multicaixa': return '<i class="fas fa-credit-card text-secondary"></i> Multicaixa';
        default: return '<i class="fas fa-question-circle"></i> ' . ucfirst($metodo);
    }
}

function getTipoPagamentoBadge($tipo) {
    switch ($tipo) {
        case 'mensalidade': return '<span class="badge bg-primary">Mensalidade</span>';
        case 'matricula': return '<span class="badge bg-success">Matrícula</span>';
        case 'certificado': return '<span class="badge bg-info">Certificado</span>';
        case 'material': return '<span class="badge bg-warning">Material</span>';
        default: return '<span class="badge bg-secondary">' . ucfirst($tipo) . '</span>';
    }
}

// ============================================
// EXPORTAÇÃO COM BIBLIOTECAS
// ============================================
if ($exportar == 'excel_lib') {
    $filtros = [
        'ano' => $ano_filtro,
        'tipo' => $tipo_filtro
    ];
    ExportManager::exportToExcel($pagamentos, $aluno, $turma, $total_valor, $filtros);
}

if ($exportar == 'pdf_lib') {
    $filtros = [
        'ano' => $ano_filtro,
        'tipo' => $tipo_filtro
    ];
    ExportManager::exportToPDF($pagamentos, $aluno, $turma, $total_valor, $filtros);
}

// Exportação simples (mantida para compatibilidade)
if ($exportar == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pagamentos_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Data', 'Tipo', 'Descrição', 'Valor', 'Forma de Pagamento', 'Fatura', 'Referência']);
    
    foreach ($pagamentos as $p) {
        fputcsv($output, [
            date('d/m/Y', strtotime($p['data_pagamento'])),
            ucfirst($p['tipo_pagamento']),
            $p['descricao_completa'],
            number_format($p['valor'], 2, ',', '.'),
            ucfirst($p['metodo_pagamento']),
            $p['numero_fatura'],
            $p['numero_referencia'] ?? '-'
        ]);
    }
    fclose($output);
    exit;
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Pagamentos | Área do Aluno</title>
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
        
        .comprovativo-link {
            color: #006B3E;
            text-decoration: none;
        }
        .comprovativo-link:hover {
            text-decoration: underline;
        }
        
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
                <h2><i class="fas fa-credit-card"></i> Meus Pagamentos</h2>
                <p class="text-muted">Histórico completo de todos os seus pagamentos</p>
            </div>
            <div class="no-print">
               <div class="btn-group">
    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
        <i class="fas fa-download"></i> Exportar
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="#" onclick="showExportOptions()"><i class="fas fa-file-excel"></i> Exportar Excel (Biblioteca)</a></li>
        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['exportar' => 'excel_lib'])); ?>"><i class="fas fa-file-excel text-success"></i> Excel - Formatação Completa</a></li>
        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['exportar' => 'pdf_lib'])); ?>"><i class="fas fa-file-pdf text-danger"></i> PDF Profissional</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['exportar' => 'csv'])); ?>"><i class="fas fa-file-csv"></i> CSV (Simples)</a></li>
        <li><a class="dropdown-item" href="#" onclick="window.print();"><i class="fas fa-print"></i> Imprimir</a></li>
    </ul>
</div>
            </div>
        </div>
        
        <!-- Informações do Aluno -->
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
                            <i class="fas fa-money-bill"></i>
                            <strong>Total Pago:</strong> <?php echo formatarMoeda($total_valor); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_pagamentos; ?></div>
                    <div class="stat-label"><i class="fas fa-receipt"></i> Total de Pagamentos</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($total_valor); ?></div>
                    <div class="stat-label"><i class="fas fa-money-bill-wave"></i> Valor Total Pago</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo count($tipos_pagamento); ?></div>
                    <div class="stat-label"><i class="fas fa-tags"></i> Tipos de Pagamento</div>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas por Tipo (Gráfico) -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Distribuição por Tipo</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="graficoTipos" height="100"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Pagamentos por Ano</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="graficoAnos" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Ano</label>
                        <select name="ano" class="form-select">
                            <option value="0">Todos os anos</option>
                            <?php foreach ($anos_disponiveis as $ano): ?>
                            <option value="<?php echo $ano; ?>" <?php echo $ano_filtro == $ano ? 'selected' : ''; ?>>
                                <?php echo $ano; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tipo de Pagamento</label>
                        <select name="tipo" class="form-select">
                            <option value="todos">Todos</option>
                            <option value="mensalidade" <?php echo $tipo_filtro == 'mensalidade' ? 'selected' : ''; ?>>Mensalidade</option>
                            <option value="matricula" <?php echo $tipo_filtro == 'matricula' ? 'selected' : ''; ?>>Matrícula</option>
                            <option value="certificado" <?php echo $tipo_filtro == 'certificado' ? 'selected' : ''; ?>>Certificado</option>
                            <option value="material" <?php echo $tipo_filtro == 'material' ? 'selected' : ''; ?>>Material</option>
                            <option value="outro" <?php echo $tipo_filtro == 'outro' ? 'selected' : ''; ?>>Outro</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($ano_filtro > 0 || $tipo_filtro != 'todos'): ?>
                        <a href="pagamentos.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i> Limpar
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tabela de Pagamentos -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Histórico de Pagamentos</h5>
                <small>Total de <?php echo $total_pagamentos; ?> registro(s)</small>
            </div>
            <div class="card-body">
                <?php if (empty($pagamentos)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhum pagamento encontrado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Descrição</th>
                                    <th class="text-end">Valor</th>
                                    <th>Forma de Pagamento</th>
                                    <th>Fatura</th>
                                    <th>Comprovativo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagamentos as $pg): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                                    <td><?php echo getTipoPagamentoBadge($pg['tipo_pagamento']); ?></td>
                                    <td><?php echo htmlspecialchars($pg['descricao_completa']); ?></small></td>
                                    <td class="text-end text-success fw-bold"><?php echo formatarMoeda($pg['valor']); ?></td>
                                    <td><?php echo getMetodoPagamentoIcone($pg['metodo_pagamento']); ?></td>
                                    <td><small class="text-muted"><?php echo $pg['numero_fatura']; ?></small></td>
                                    <td>
                                        <?php if (!empty($pg['comprovativo_path'])): ?>
                                        <a href="<?php echo $pg['comprovativo_path']; ?>" target="_blank" class="comprovativo-link" title="Ver comprovativo">
                                            <i class="fas fa-file-image"></i> Ver
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo formatarMoeda($total_valor); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Resumo da Situação -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações Importantes</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <i class="fas fa-lightbulb"></i>
                            <strong>Dica:</strong> Mantenha seus comprovantes de pagamento em local seguro.
                            Em caso de dúvidas, procure a secretaria da escola.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <strong>Status:</strong>
                            <?php if ($total_valor > 0): ?>
                                Você já realizou <?php echo $total_pagamentos; ?> pagamento(s), totalizando <?php echo formatarMoeda($total_valor); ?>.
                            <?php else: ?>
                                Nenhum pagamento registrado ainda.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>


           
<!-- Modal de opções avançadas -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-download"></i> Opções de Exportação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-file-excel fa-3x text-success mb-3"></i>
                                <h6>Excel Profissional</h6>
                                <p class="small">Formatação completa com cabeçalho, cores e bordas</p>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['exportar' => 'excel_lib'])); ?>" class="btn btn-sm btn-success">
                                    Exportar Excel
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                <h6>PDF Profissional</h6>
                                <p class="small">Documento formatado para impressão e arquivo</p>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['exportar' => 'pdf_lib'])); ?>" class="btn btn-sm btn-danger">
                                    Exportar PDF
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Os documentos gerados incluem informações do aluno, filtros aplicados e formatação profissional.
                </div>
            </div>
        </div>
    </div>
</div>



    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>

        
function showExportOptions() {
    new bootstrap.Modal(document.getElementById('exportModal')).show();
}
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Gráfico de Distribuição por Tipo
        const tiposLabels = <?php echo json_encode(array_keys($tipos_pagamento)); ?>;
        const tiposValues = <?php echo json_encode(array_column($tipos_pagamento, 'total')); ?>;
        
        new Chart(document.getElementById('graficoTipos'), {
            type: 'pie',
            data: {
                labels: tiposLabels.map(t => t === 'mensalidade' ? 'Mensalidade' : t === 'matricula' ? 'Matrícula' : t === 'certificado' ? 'Certificado' : t === 'material' ? 'Material' : 'Outro'),
                datasets: [{
                    data: tiposValues,
                    backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#ffc107', '#6c757d']
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
        
        // Gráfico de Pagamentos por Ano
        const anosLabels = <?php echo json_encode(array_column($estatisticas_por_ano, 'ano')); ?>;
        const anosValues = <?php echo json_encode(array_column($estatisticas_por_ano, 'total')); ?>;
        
        new Chart(document.getElementById('graficoAnos'), {
            type: 'bar',
            data: {
                labels: anosLabels,
                datasets: [{
                    label: 'Total Pago (Kz)',
                    data: anosValues,
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
                                return 'Total: ' + context.raw.toLocaleString('pt-AO') + ' Kz';
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