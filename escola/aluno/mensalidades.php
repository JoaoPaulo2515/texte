<?php
// aluno/financeiro/mensalidades.php - Mensalidades do Aluno

require_once __DIR__ . '/../../config/database.php';
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


/*

// Buscar mensalidades do aluno
$sql_mensalidades = "SELECT m.*, 
                     DATE_FORMAT(m.data_vencimento, '%m') as mes_num,
                     DATE_FORMAT(m.data_vencimento, '%M') as mes_nome,
                     YEAR(m.data_vencimento) as ano,
                     CASE 
                         WHEN m.status = 'pago' THEN 'pago'
                         WHEN m.data_vencimento < CURDATE() AND m.status != 'pago' THEN 'atrasado'
                         ELSE 'pendente'
                     END as status_atual,
                     DATEDIFF(CURDATE(), m.data_vencimento) as dias_atraso
                     FROM mensalidades m
                     WHERE m.aluno_id = :aluno_id 
                     AND m.escola_id = :escola_id
                     ORDER BY m.data_vencimento ASC";

$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$mensalidades_lista = $stmt_mensalidades->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_mensalidades = count($mensalidades_lista);
$total_pago = array_sum(array_column($mensalidades_lista, 'valor_pago'));
$total_devedor = array_sum(array_column($mensalidades_lista, 'valor')) - $total_pago;
$total_pagas = count(array_filter($mensalidades_lista, function($m) { return $m['status_atual'] == 'pago'; }));
$total_pendentes = count(array_filter($mensalidades_lista, function($m) { return $m['status_atual'] == 'pendente'; }));
$total_atrasadas = count(array_filter($mensalidades_lista, function($m) { return $m['status_atual'] == 'atrasado'; }));
$percentual_adimplencia = $total_mensalidades > 0 ? ($total_pagas / $total_mensalidades) * 100 : 0;
*/


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
    
   <style>
    
    /* ==============================================
   ESTATÍSTICAS FINANCEIRAS - DESIGN MODERNO
   ============================================== */

/* Grid Principal */
.stats-financeiro-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

/* Cards Principais */
.stat-financeiro-card {
    background: white;
    border-radius: 24px;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    border: 1px solid rgba(0,0,0,0.03);
    position: relative;
}

.stat-financeiro-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15);
}

.stat-card-inner {
    padding: 22px;
    position: relative;
    z-index: 1;
}

/* Cores dos Cards */
.stat-financeiro-card.total-mensalidades .stat-icon-bg { background: linear-gradient(135deg, #4361ee, #3b82f6); }
.stat-financeiro-card.total-pago .stat-icon-bg { background: linear-gradient(135deg, #28a745, #20c997); }
.stat-financeiro-card.saldo-devedor .stat-icon-bg { background: linear-gradient(135deg, #dc3545, #fd7e14); }
.stat-financeiro-card.adimplencia .stat-icon-bg { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

.stat-icon-wrapper {
    position: absolute;
    top: 20px;
    right: 20px;
}

.stat-icon-bg {
    width: 50px;
    height: 50px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.9;
}

.stat-icon-bg i {
    font-size: 1.6rem;
    color: white;
}

.stat-info {
    margin-top: 10px;
}

.stat-value {
    font-size: 2rem;
    font-weight: 800;
    margin-bottom: 8px;
    color: #2c3e50;
}

.stat-label {
    font-size: 0.85rem;
    font-weight: 500;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.stat-trend {
    font-size: 0.7rem;
    color: #95a5a6;
    display: flex;
    align-items: center;
    gap: 5px;
}

/* Barra de Progresso */
.progress-container {
    margin-top: 12px;
}

.progress-bar-custom {
    height: 8px;
    background: #f0f0f0;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.progress-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.5s ease;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 5px;
}

.progress-fill.fill-success { background: linear-gradient(90deg, #28a745, #20c997); }
.progress-fill.fill-warning { background: linear-gradient(90deg, #f59e0b, #ffc107); }
.progress-fill.fill-danger { background: linear-gradient(90deg, #dc3545, #fd7e14); }

.progress-percent {
    font-size: 0.65rem;
    color: white;
    font-weight: 600;
}

.stat-decoration {
    position: absolute;
    bottom: 15px;
    right: 20px;
    font-size: 3rem;
    opacity: 0.05;
    color: #2c3e50;
}

/* Grid de Status */
.stats-status-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

/* Cards de Status */
.stat-status-card {
    background: white;
    border-radius: 20px;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    gap: 18px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border: 1px solid rgba(0,0,0,0.03);
    position: relative;
    overflow: hidden;
}

.stat-status-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 25px -10px rgba(0,0,0,0.1);
}

.stat-status-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 5px;
    height: 100%;
}

.stat-status-card.pagas::before { background: linear-gradient(135deg, #28a745, #20c997); }
.stat-status-card.pendentes::before { background: linear-gradient(135deg, #f59e0b, #ffc107); }
.stat-status-card.atrasadas::before { background: linear-gradient(135deg, #dc3545, #fd7e14); }

.status-icon {
    width: 55px;
    height: 55px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-status-card.pagas .status-icon { background: #d4edda; color: #28a745; }
.stat-status-card.pendentes .status-icon { background: #fff3cd; color: #f59e0b; }
.stat-status-card.atrasadas .status-icon { background: #f8d7da; color: #dc3545; }

.status-icon i {
    font-size: 1.8rem;
}

.status-info {
    flex: 1;
}

.status-value {
    font-size: 1.8rem;
    font-weight: 800;
    color: #2c3e50;
    line-height: 1;
}

.status-label {
    font-size: 0.8rem;
    color: #6c757d;
    margin-top: 4px;
}

.status-percent {
    font-size: 0.7rem;
    color: #95a5a6;
    margin-top: 5px;
}

.status-progress {
    width: 100px;
}

.mini-progress {
    height: 4px;
    background: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
}

.mini-progress-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s ease;
}

/* Responsividade */
@media (max-width: 1200px) {
    .stats-financeiro-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .stats-status-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-financeiro-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-status-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .stat-status-card {
        padding: 15px 18px;
    }
    
    .status-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
    }
    
    .status-icon i {
        font-size: 1.5rem;
    }
    
    .status-value {
        font-size: 1.5rem;
    }
}

   </style>

 <style>

     /* Botão Exportar */
.btn-export {
    background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 500;
    transition: all 0.3s;
}

.btn-export:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    color: white;
}

.dropdown-item {
    padding: 10px 20px;
    transition: all 0.2s;
    cursor: pointer;
}

.dropdown-item:hover {
    background: #f8f9fa;
    transform: translateX(5px);
}

.dropdown-item i {
    width: 25px;
}

  </style>
   
<style>
.dropdown-menu-custom {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    min-width: 180px;
    padding: 8px 0;
}

.dropdown-menu-custom a {
    display: block;
    padding: 8px 16px;
    text-decoration: none;
    color: #333;
    transition: background 0.2s;
}

.dropdown-menu-custom a:hover {
    background: #f0f0f0;
}

.dropdown-menu-custom hr {
    margin: 8px 0;
}
</style>


</head>
<body>
   <?php include 'includes/menu_aluno.php'; ?>
</br></br></br>
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-calendar-dollar"></i> Minhas Mensalidades</h2>
                <p class="text-muted">Acompanhe o status das suas mensalidades</p>
            </div>

          <div class="no-print">
    <div class="btn-group">
        <button type="button" class="btn btn-export" onclick="toggleDropdown()">
            <i class="fas fa-download"></i> Exportar
        </button>
        <div id="dropdownManual" class="dropdown-menu-custom" style="display: none; position: absolute; z-index: 1000;">
            <a href="#" onclick="exportarMensalidades('csv'); return false;">📄 Exportar CSV</a>
            <a href="#" onclick="exportarMensalidades('excel'); return false;">📊 Exportar Excel</a>
            <a href="#" onclick="exportarMensalidades('pdf'); return false;">📑 Exportar PDF</a>
            <hr>
            <a href="#" onclick="window.print(); return false;">🖨️ Imprimir</a>
        </div>
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
        

           <!-- ==============================================
     ESTATÍSTICAS FINANCEIRAS - DESIGN MODERNO
     ============================================== -->

<!-- Primeira linha: Cards Principais -->
<div class="stats-financeiro-grid">
    <!-- Total de Mensalidades -->
    <div class="stat-financeiro-card total-mensalidades">
        <div class="stat-card-inner">
            <div class="stat-icon-wrapper">
                <div class="stat-icon-bg">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo number_format($total_mensalidades); ?></div>
                <div class="stat-label">Total de Mensalidades</div>
                <div class="stat-trend">
                    <i class="fas fa-calendar-alt"></i> Ano letivo <?php echo date('Y'); ?>
                </div>
            </div>
            <div class="stat-decoration">
                <i class="fas fa-receipt"></i>
            </div>
        </div>
    </div>

    <!-- Total Pago -->
    <div class="stat-financeiro-card total-pago">
        <div class="stat-card-inner">
            <div class="stat-icon-wrapper">
                <div class="stat-icon-bg">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
            <div class="stat-info">
                <div class="stat-value text-success"><?php echo formatarMoeda($total_pago); ?> Kz</div>
                <div class="stat-label">Total Pago</div>
                <div class="stat-trend">
                    <i class="fas fa-arrow-up text-success"></i> 
                    <?php echo $total_pagas; ?> mensalidades quitadas
                </div>
            </div>
            <div class="stat-decoration">
                <i class="fas fa-coins"></i>
            </div>
        </div>
    </div>

    <!-- Saldo Devedor -->
    <div class="stat-financeiro-card saldo-devedor">
        <div class="stat-card-inner">
            <div class="stat-icon-wrapper">
                <div class="stat-icon-bg">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
            <div class="stat-info">
                <div class="stat-value text-danger"><?php echo formatarMoeda($total_devedor); ?> Kz</div>
                <div class="stat-label">Saldo Devedor</div>
                <div class="stat-trend">
                    <i class="fas fa-clock text-warning"></i> 
                    <?php echo $total_pendentes + $total_atrasadas; ?> pendentes
                </div>
            </div>
            <div class="stat-decoration">
                <i class="fas fa-money-bill-wave"></i>
            </div>
        </div>
    </div>

    <!-- Adimplência -->
    <div class="stat-financeiro-card adimplencia">
        <div class="stat-card-inner">
            <div class="stat-icon-wrapper">
                <div class="stat-icon-bg">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
            <div class="stat-info">
                <div class="stat-value <?php echo $percentual_adimplencia >= 70 ? 'text-success' : ($percentual_adimplencia >= 40 ? 'text-warning' : 'text-danger'); ?>">
                    <?php echo number_format($percentual_adimplencia, 1); ?>%
                </div>
                <div class="stat-label">Taxa de Adimplência</div>
                <div class="progress-container">
                    <div class="progress-bar-custom">
                        <div class="progress-fill <?php echo $percentual_adimplencia >= 70 ? 'fill-success' : ($percentual_adimplencia >= 40 ? 'fill-warning' : 'fill-danger'); ?>" 
                             style="width: <?php echo $percentual_adimplencia; ?>%">
                            <span class="progress-percent"><?php echo number_format($percentual_adimplencia, 1); ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="stat-decoration">
                <i class="fas fa-percent"></i>
            </div>
        </div>
    </div>
</div>

<!-- Segunda linha: Status das Mensalidades -->
<div class="stats-status-grid">
    <!-- Pagas -->
    <div class="stat-status-card pagas">
        <div class="status-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="status-info">
            <div class="status-value"><?php echo $total_pagas; ?></div>
            <div class="status-label">Mensalidades Pagas</div>
            <div class="status-percent"><?php echo $total_mensalidades > 0 ? round(($total_pagas / $total_mensalidades) * 100, 1) : 0; ?>% do total</div>
        </div>
        <div class="status-progress">
            <div class="mini-progress">
                <div class="mini-progress-fill bg-success" style="width: <?php echo $total_mensalidades > 0 ? ($total_pagas / $total_mensalidades) * 100 : 0; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Pendentes -->
    <div class="stat-status-card pendentes">
        <div class="status-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="status-info">
            <div class="status-value"><?php echo $total_pendentes; ?></div>
            <div class="status-label">Mensalidades Pendentes</div>
            <div class="status-percent"><?php echo $total_mensalidades > 0 ? round(($total_pendentes / $total_mensalidades) * 100, 1) : 0; ?>% do total</div>
        </div>
        <div class="status-progress">
            <div class="mini-progress">
                <div class="mini-progress-fill bg-warning" style="width: <?php echo $total_mensalidades > 0 ? ($total_pendentes / $total_mensalidades) * 100 : 0; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Atrasadas -->
    <div class="stat-status-card atrasadas">
        <div class="status-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="status-info">
            <div class="status-value"><?php echo $total_atrasadas; ?></div>
            <div class="status-label">Mensalidades Atrasadas</div>
            <div class="status-percent"><?php echo $total_mensalidades > 0 ? round(($total_atrasadas / $total_mensalidades) * 100, 1) : 0; ?>% do total</div>
        </div>
        <div class="status-progress">
            <div class="mini-progress">
                <div class="mini-progress-fill bg-danger" style="width: <?php echo $total_mensalidades > 0 ? ($total_atrasadas / $total_mensalidades) * 100 : 0; ?>%"></div>
            </div>
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
function toggleDropdown() {
    var dropdown = document.getElementById('dropdownManual');
    if (dropdown.style.display === 'none') {
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
    }
}

// Fechar dropdown ao clicar fora
document.addEventListener('click', function(event) {
    var dropdown = document.getElementById('dropdownManual');
    var btn = document.querySelector('.btn-export');
    if (!btn.contains(event.target) && dropdown) {
        dropdown.style.display = 'none';
    }
});
</script>
      
    <script>
// Dados das mensalidades do PHP para JavaScript
const mensalidadesData = {
    aluno: '<?php echo addslashes($aluno['nome']); ?>',
    matricula: '<?php echo $aluno['matricula']; ?>',
    turma: '<?php echo addslashes(($turma['ano'] ?? '') . 'ª - ' . ($turma['nome'] ?? 'Não atribuída')); ?>',
    total_mensalidades: <?php echo $total_mensalidades ?? 0; ?>,
    total_pago: <?php echo $total_pago ?? 0; ?>,
    total_devedor: <?php echo $total_devedor ?? 0; ?>,
    percentual_adimplencia: <?php echo $percentual_adimplencia ?? 0; ?>,
    total_pagas: <?php echo $total_pagas ?? 0; ?>,
    total_pendentes: <?php echo $total_pendentes ?? 0; ?>,
    total_atrasadas: <?php echo $total_atrasadas ?? 0; ?>,
    mensalidades: []
};

<?php if (isset($mensalidades_lista) && !empty($mensalidades_lista)): ?>
    <?php foreach ($mensalidades_lista as $mensalidade): ?>
    mensalidadesData.mensalidades.push({
        mes: '<?php echo $mensalidade['mes']; ?>',
        ano: <?php echo $mensalidade['ano']; ?>,
        data_vencimento: '<?php echo date('d/m/Y', strtotime($mensalidade['data_vencimento'])); ?>',
        data_pagamento: '<?php echo $mensalidade['data_pagamento'] ? date('d/m/Y', strtotime($mensalidade['data_pagamento'])) : '-'; ?>',
        valor: <?php echo $mensalidade['valor']; ?>,
        valor_pago: <?php echo $mensalidade['valor_pago'] ?? 0; ?>,
        status: '<?php echo $mensalidade['status']; ?>',
        atraso: <?php echo $mensalidade['dias_atraso'] ?? 0; ?>
    });
    <?php endforeach; ?>
<?php endif; ?>

// Função para exportar mensalidades
function exportarMensalidades(tipo) {
    if (tipo === 'csv') {
        exportarMensalidadesCSV();
    } else if (tipo === 'excel') {
        exportarMensalidadesExcel();
    } else if (tipo === 'pdf') {
        exportarMensalidadesPDF();
    }
}

// Exportar CSV
function exportarMensalidadesCSV() {
    let csv = "\uFEFF"; // BOM para UTF-8
    
    // Cabeçalho do relatório
    csv += "RELATÓRIO DE MENSALIDADES\n";
    csv += `Aluno: ${mensalidadesData.aluno}\n`;
    csv += `Matrícula: ${mensalidadesData.matricula}\n`;
    csv += `Turma: ${mensalidadesData.turma}\n`;
    csv += `Data Emissão: ${new Date().toLocaleString('pt-AO')}\n\n`;
    
    // Resumo
    csv += "RESUMO FINANCEIRO\n";
    csv += `Total de Mensalidades;${mensalidadesData.total_mensalidades}\n`;
    csv += `Total Pago;${formatarMoedaCSV(mensalidadesData.total_pago)} Kz\n`;
    csv += `Saldo Devedor;${formatarMoedaCSV(mensalidadesData.total_devedor)} Kz\n`;
    csv += `Percentual de Adimplência;${mensalidadesData.percentual_adimplencia}%\n`;
    csv += `Mensalidades Pagas;${mensalidadesData.total_pagas}\n`;
    csv += `Mensalidades Pendentes;${mensalidadesData.total_pendentes}\n`;
    csv += `Mensalidades Atrasadas;${mensalidadesData.total_atrasadas}\n\n`;
    
    // Detalhamento
    csv += "DETALHAMENTO DAS MENSALIDADES\n";
    csv += "Mês/Ano;Data Vencimento;Data Pagamento;Valor;Valor Pago;Status;Dias Atraso\n";
    
    mensalidadesData.mensalidades.forEach(m => {
        let statusTexto = m.status === 'pago' ? 'PAGO' : (m.status === 'pendente' ? 'PENDENTE' : 'ATRASADO');
        csv += `${m.mes}/${m.ano};${m.data_vencimento};${m.data_pagamento};${formatarMoedaCSV(m.valor)} Kz;${formatarMoedaCSV(m.valor_pago)} Kz;${statusTexto};${m.atraso}\n`;
    });
    
    // Rodapé
    csv += `\n\nRelatório gerado em: ${new Date().toLocaleString('pt-AO')}\n`;
    csv += `SIGE Angola - Sistema de Gestão Escolar\n`;
    
    // Download
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.setAttribute('download', `mensalidades_${mensalidadesData.aluno}_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// Exportar Excel
function exportarMensalidadesExcel() {
    let html = `
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Relatório de Mensalidades</title>
            <style>
                body { font-family: Arial, sans-serif; }
                h1 { color: #006B3E; }
                .titulo { background: #006B3E; color: white; padding: 10px; }
                .resumo { background: #f0f0f0; }
                th { background: #1A2A6C; color: white; }
                .pago { color: green; font-weight: bold; }
                .pendente { color: orange; font-weight: bold; }
                .atrasado { color: red; font-weight: bold; }
                .total { background: #e8f5e9; font-weight: bold; }
            </style>
        </head>
        <body>
            <h1>RELATÓRIO DE MENSALIDADES</h1>
            
            <h2>Dados do Aluno</h2>
            <table border="1" cellpadding="5" cellspacing="0" width="100%">
                <tr><td width="150"><strong>Aluno:</strong></td><td>${mensalidadesData.aluno}</td></tr>
                <tr><td><strong>Matrícula:</strong></td><td>${mensalidadesData.matricula}</td></tr>
                <tr><td><strong>Turma:</strong></td><td>${mensalidadesData.turma}</td></tr>
                <tr><td><strong>Data Emissão:</strong></td><td>${new Date().toLocaleString('pt-AO')}</td></tr>
            </table>
            
            <h2>Resumo Financeiro</h2>
            <table border="1" cellpadding="5" cellspacing="0" width="100%">
                <tr class="resumo"><td><strong>Total de Mensalidades:</strong></td><td>${mensalidadesData.total_mensalidades}</td></tr>
                <tr class="resumo"><td><strong>Total Pago:</strong></td><td class="pago">${formatarMoedaCSV(mensalidadesData.total_pago)} Kz</td></tr>
                <tr class="resumo"><td><strong>Saldo Devedor:</strong></td><td class="atrasado">${formatarMoedaCSV(mensalidadesData.total_devedor)} Kz</td></tr>
                <tr class="resumo"><td><strong>Percentual de Adimplência:</strong></td><td>${mensalidadesData.percentual_adimplencia}%</td></tr>
                <tr class="resumo"><td><strong>Mensalidades Pagas:</strong></td><td class="pago">${mensalidadesData.total_pagas}</td></tr>
                <tr class="resumo"><td><strong>Mensalidades Pendentes:</strong></td><td class="pendente">${mensalidadesData.total_pendentes}</td></tr>
                <tr class="resumo"><td><strong>Mensalidades Atrasadas:</strong></td><td class="atrasado">${mensalidadesData.total_atrasadas}</td></tr>
            </table>
            
            <h2>Detalhamento das Mensalidades</h2>
            <table>
                <thead>
                    <tr>
                        <th>Mês/Ano</th>
                        <th>Data Vencimento</th>
                        <th>Data Pagamento</th>
                        <th>Valor</th>
                        <th>Valor Pago</th>
                        <th>Status</th>
                        <th>Dias Atraso</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    mensalidadesData.mensalidades.forEach(m => {
        let statusClass = m.status === 'pago' ? 'pago' : (m.status === 'pendente' ? 'pendente' : 'atrasado');
        let statusTexto = m.status === 'pago' ? 'PAGO' : (m.status === 'pendente' ? 'PENDENTE' : 'ATRASADO');
        html += `
            <tr class="${statusClass}">
                <td>${m.mes}/${m.ano}</td>
                <td>${m.data_vencimento}</td>
                <td>${m.data_pagamento}</td>
                <td>${formatarMoedaCSV(m.valor)} Kz</td>
                <td>${formatarMoedaCSV(m.valor_pago)} Kz</td>
                <td>${statusTexto}</td>
                <td>${m.atraso}</td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
                <tfoot>
                    <tr class="total">
                        <td colspan="3"><strong>TOTAIS</strong></td>
                        <td><strong>${formatarMoedaCSV(mensalidadesData.mensalidades.reduce((sum, m) => sum + m.valor, 0))} Kz</strong></td>
                        <td><strong>${formatarMoedaCSV(mensalidadesData.mensalidades.reduce((sum, m) => sum + m.valor_pago, 0))} Kz</strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            
            <h3>Legenda</h3>
            <ul>
                <li><span class="pago">🟢 PAGO</span> - Mensalidade quitada</li>
                <li><span class="pendente">🟡 PENDENTE</span> - Aguardando pagamento</li>
                <li><span class="atrasado">🔴 ATRASADO</span> - Vencida e não paga</li>
            </ul>
            
            <hr>
            <p><small>Relatório gerado pelo SIGE Angola - Sistema de Gestão Escolar</small></p>
            <p><small>Data: ${new Date().toLocaleString('pt-AO')}</small></p>
        </body>
        </html>
    `;
    
    const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.setAttribute('download', `mensalidades_${mensalidadesData.aluno}_${new Date().toISOString().slice(0,10)}.xls`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// Exportar PDF (imprimir a página atual)
// Função para exportar PDF das mensalidades
function exportarMensalidadesPDF() {
    // Abrir nova janela com o PDF
    window.open('exportar_mensalidades_pdf.php', '_blank', 'width=900,height=700,toolbar=yes,scrollbars=yes,resizable=yes');
}

// Função auxiliar para formatar moeda no CSV
function formatarMoedaCSV(valor) {
    return valor.toLocaleString('pt-AO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>
  
  
  
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