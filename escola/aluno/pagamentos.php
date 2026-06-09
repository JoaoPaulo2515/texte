<?php
// aluno/financeiro/pagamentos.php - Histórico de Pagamentos do Aluno

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
        .stat-label { color: #f7f8f8; font-size: 0.8rem; margin-top: 5px; }
        
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
     
    <style>
/* Adicione este CSS no final do <style> existente */

/* ==============================================
   ESTATÍSTICAS MODERNAS - CORES E ÍCONES
   ============================================== */

/* Cards de Estatísticas Modernos */
.stat-card {
    position: relative;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    border-radius: 20px;
    border: none;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.2);
}

/* Efeito de brilho no hover */
.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.6s;
}

.stat-card:hover::before {
    left: 100%;
}

/* Ícones decorativos no fundo */
.stat-card::after {
    content: '';
    position: absolute;
    bottom: 10px;
    right: 10px;
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    font-size: 4rem;
    opacity: 0.1;
    pointer-events: none;
}

.stat-card:nth-child(1)::after {
    content: '\f0f6';
}
.stat-card:nth-child(2)::after {
    content: '\f00c';
}
.stat-card:nth-child(3)::after {
    content: '\f06a';
}
.stat-card:nth-child(4)::after {
    content: '\f080';
}

/* Cores gradientes para cada card */
.stat-card:nth-child(1) {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.stat-card:nth-child(2) {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
}

.stat-card:nth-child(3) {
    background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
    color: white;
}

.stat-card:nth-child(4) {
    background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
    color: white;
}

/* Ícone dentro do card */
.stat-card .stat-icon {
    position: absolute;
    top: 15px;
    right: 15px;
    font-size: 2rem;
    opacity: 0.2;
    transition: all 0.3s ease;
}

.stat-card:hover .stat-icon {
    opacity: 0.4;
    transform: scale(1.1);
}

.stat-value {
    font-size: 2rem;
    font-weight: 800;
    margin-bottom: 5px;
    position: relative;
    z-index: 1;
    animation: countUp 0.6s ease-out;
}

.stat-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.9;
    position: relative;
    z-index: 1;
}

/* Animações */
@keyframes countUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Efeito de clique (ripple) */
.stat-card {
    position: relative;
    overflow: hidden;
}

.ripple-effect {
    position: absolute;
    border-radius: 50%;
    background-color: rgba(255, 255, 255, 0.4);
    transform: scale(0);
    animation: ripple 0.6s linear;
    pointer-events: none;
}

@keyframes ripple {
    to {
        transform: scale(4);
        opacity: 0;
    }
}

/* ==============================================
   INFO ALUNO MODERNO
   ============================================== */
.info-aluno {
    background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
    color: white;
    border-radius: 20px;
    padding: 20px;
    border: none;
    transition: transform 0.3s ease;
}

.info-aluno:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -8px rgba(0,0,0,0.2);
}

.info-aluno i {
    margin-right: 8px;
    opacity: 0.9;
}

.info-aluno .row div {
    padding: 8px 0;
}

/* ==============================================
   GRÁFICOS MODERNOS
   ============================================== */
.card {
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 30px -10px rgba(0, 0, 0, 0.1);
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #006B3E;
    padding: 15px 20px;
}

.card-header h5 {
    font-weight: 700;
    color: #2c3e50;
}

.card-header i {
    color: #006B3E;
    margin-right: 8px;
}

/* ==============================================
   TABELA MODERNA
   ============================================== */
.table {
    border-radius: 15px;
    overflow: hidden;
}

.table thead th {
    background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
    color: white;
    font-weight: 600;
    border: none;
    padding: 12px 15px;
}

.table tbody tr {
    transition: all 0.3s ease;
}

.table tbody tr:hover {
    background: #f8f9fa;
    transform: translateX(5px);
}

.table tbody td {
    vertical-align: middle;
    padding: 12px 15px;
}

/* Badges modernos */
.badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-weight: 500;
}

.badge.bg-primary {
    background: linear-gradient(135deg, #667eea, #764ba2) !important;
}

.badge.bg-success {
    background: linear-gradient(135deg, #28a745, #20c997) !important;
}

.badge.bg-info {
    background: linear-gradient(135deg, #17a2b8, #0dcaf0) !important;
}

.badge.bg-warning {
    background: linear-gradient(135deg, #ffc107, #fd7e14) !important;
    color: #333;
}

/* Botões */
.btn-primary {
    background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
    border: none;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,107,62,0.3);
}

.btn-secondary {
    background: #6c757d;
    border: none;
    transition: all 0.3s ease;
}

.btn-secondary:hover {
    transform: translateY(-2px);
}

/* Dropdown */
.dropdown-menu {
    border-radius: 15px;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    overflow: hidden;
}

.dropdown-item {
    padding: 10px 20px;
    transition: all 0.2s;
}

.dropdown-item:hover {
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    transform: translateX(5px);
}

.dropdown-item i {
    width: 25px;
}

/* Responsividade */
@media (max-width: 768px) {
    .stat-value {
        font-size: 1.5rem;
    }
    
    .info-aluno {
        text-align: center;
    }
    
    .info-aluno .row div {
        margin-bottom: 10px;
    }
}


/* Melhorias nos gráficos */
#graficoTipos {
    filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
}

#graficoAnos {
    filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
}

/* Animações dos gráficos */
canvas {
    transition: all 0.3s ease;
}

canvas:hover {
    transform: scale(1.02);
}
    

/* Botão Exportar Estilizado */
.btn-export {
    background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 500;
    transition: all 0.3s ease;
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
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    transform: translateX(5px);
}

.dropdown-item i {
    width: 25px;
    margin-right: 5px;
}
    </style>

<style>
/* ==============================================
   BOTÃO DE EXPORTAÇÃO - VERSÃO PREMIUM
   ============================================== */

.btn-group {
    position: relative;
    display: inline-block;
}

.btn-export {
    background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 30px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    letter-spacing: 0.5px;
}

.btn-export:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,107,62,0.35);
    background: linear-gradient(135deg, #2a3a7c 0%, #1a5d4e 100%);
}

.btn-export:active {
    transform: translateY(1px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.btn-export i {
    font-size: 16px;
    transition: transform 0.3s;
}

.btn-export:hover i {
    transform: translateY(2px);
}

/* Dropdown */
.dropdown-menu-custom {
    position: absolute;
    top: calc(100% + 5px);
    right: 0;
    min-width: 220px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.15);
    overflow: hidden;
    z-index: 1000;
    animation: slideDown 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    transform-origin: top right;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: scaleY(0);
    }
    to {
        opacity: 1;
        transform: scaleY(1);
    }
}

.dropdown-menu-custom a {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 18px;
    color: #2c3e50;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    position: relative;
}

.dropdown-menu-custom a::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 0;
    background: #006B3E;
    transition: width 0.2s;
    opacity: 0.1;
}

.dropdown-menu-custom a:hover::before {
    width: 100%;
}

.dropdown-menu-custom a:hover {
    padding-left: 24px;
    color: #006B3E;
}

.dropdown-menu-custom hr {
    margin: 8px 0;
    border: none;
    border-top: 1px solid #e9ecef;
}

/* Ícones com cores */
.dropdown-menu-custom a i {
    width: 24px;
    font-size: 16px;
    transition: transform 0.2s;
}

.dropdown-menu-custom a:hover i {
    transform: scale(1.1);
}

/* Cores dos ícones */
.dropdown-menu-custom a:first-child i { color: #2c8c3e; }
.dropdown-menu-custom a:nth-child(2) i { color: #1f7b3c; }
.dropdown-menu-custom a:nth-child(3) i { color: #dc3545; }
.dropdown-menu-custom a:last-child i { color: #6c757d; }

/* Badge de notificação (opcional) */
.btn-export .badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #dc3545;
    color: white;
    font-size: 10px;
    padding: 3px 6px;
    border-radius: 20px;
    font-weight: bold;
}

/* Responsivo */
@media (max-width: 768px) {
    .btn-export {
        padding: 8px 18px;
        font-size: 13px;
    }
    
    .dropdown-menu-custom {
        min-width: 190px;
        right: -10px;
    }
    
    .dropdown-menu-custom a {
        padding: 10px 15px;
        font-size: 13px;
    }
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
                <h2><i class="fas fa-credit-card"></i> Meus Pagamentos</h2>
                <p class="text-muted">Histórico completo de todos os seus pagamentos</p>
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
// Função para toggle do dropdown
function toggleDropdown() {
    const dropdown = document.getElementById('dropdownManual');
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
    }
}

// Fechar dropdown ao clicar fora
document.addEventListener('click', function(event) {
    const btn = document.querySelector('.btn-export');
    const dropdown = document.getElementById('dropdownManual');
    
    if (btn && dropdown && !btn.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

// Funções de exportação
function exportarMensalidades(tipo) {
    console.log('Exportando como:', tipo);
    // Fechar dropdown
    document.getElementById('dropdownManual').style.display = 'none';
    
    if (tipo === 'csv') {
        alert('Exportar CSV');
    } else if (tipo === 'excel') {
        alert('Exportar Excel');
    } else if (tipo === 'pdf') {
        alert('Exportar PDF');
    }
}
</script>
 <script>
    function exportarMensalidades(tipo) {
    if (tipo === 'csv') {
        exportarMensalidadesCSV();
    } else if (tipo === 'excel') {
        exportarMensalidadesExcel();
    } else if (tipo === 'pdf') {
        exportarMensalidadesPDF();
    }
}
// Função para exportar Excel Completo
function exportarMensalidadesExcel() {
    // Coletar dados da tabela
    const tabela = document.querySelector('.table');
    if (!tabela) {
        alert('Nenhum dado para exportar');
        return;
    }
    
    // Obter dados do aluno
    const alunoNome = document.querySelector('.info-aluno .row .col-md-4')?.innerText.replace('Aluno:', '').trim() || '<?php echo $aluno['nome']; ?>';
    const alunoMatricula = document.querySelector('.info-aluno .row .col-md-3')?.innerText.replace('Matrícula:', '').trim() || '<?php echo $aluno['matricula']; ?>';
    const turmaInfo = document.querySelector('.info-aluno .row .col-md-3:last-child')?.innerText.replace('Turma:', '').trim() || '';
    
    // Criar HTML para Excel
    let html = `
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Relatório de Pagamentos</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #006B3E; font-size: 18pt; }
                h2 { color: #1A2A6C; font-size: 14pt; margin-top: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .info { margin-bottom: 20px; padding: 10px; background: #f5f5f5; }
                table { border-collapse: collapse; width: 100%; margin-top: 15px; }
                th { background: #006B3E; color: white; padding: 10px; border: 1px solid #ddd; }
                td { padding: 8px; border: 1px solid #ddd; }
                .total { background: #f0f0f0; font-weight: bold; }
                .footer { margin-top: 30px; text-align: center; font-size: 10pt; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>RELATÓRIO DE PAGAMENTOS</h1>
                <p>Data de emissão: ${new Date().toLocaleString('pt-AO')}</p>
            </div>
            
            <div class="info">
                <h2>DADOS DO ALUNO</h2>
                <p><strong>Nome:</strong> ${alunoNome}</p>
                <p><strong>Matrícula:</strong> ${alunoMatricula}</p>
                <p><strong>Turma:</strong> ${turmaInfo}</p>
            </div>
            
            <h2>HISTÓRICO DE PAGAMENTOS</h2>
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Descrição</th>
                        <th>Valor</th>
                        <th>Forma de Pagamento</th>
                        <th>Fatura</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    // Percorrer a tabela e coletar dados
    const rows = tabela.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 7) {
            html += `<td>${cells[0]?.innerText || '-'}</td>`;
            html += `<td>${cells[1]?.innerText || '-'}</td>`;
            html += `<td>${cells[2]?.innerText || '-'}</td>`;
            html += `<td>${cells[3]?.innerText || '-'}</td>`;
            html += `<td>${cells[4]?.innerText || '-'}</td>`;
            html += `<td>${cells[5]?.innerText || '-'}</td>`;
            html += `<td>${cells[6]?.innerText || '-'}</td>`;
            html += `</tr>`;
        }
    });
    
    // Adicionar total
    const totalCell = document.querySelector('.table tfoot td.text-end');
    if (totalCell) {
        html += `<tr class="total"><td colspan="3"><strong>TOTAL</strong></td><td colspan="4"><strong>${totalCell.innerText}</strong></td></tr>`;
    }
    
    html += `
                </tbody>
            </table>
            
            <div class="footer">
                <p>Documento emitido eletronicamente por SIGE Angola - Sistema de Gestão Escolar</p>
                <p>Este documento é válido em todo território nacional</p>
            </div>
        </body>
        </html>
    `;
    
    // Criar blob e download
    const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.setAttribute('download', `relatorio_pagamentos_${new Date().toISOString().slice(0,10)}.xls`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    
    // Feedback
    Swal.fire({
        icon: 'success',
        title: 'Excel Gerado!',
        text: 'O download foi iniciado com sucesso',
        timer: 2000,
        showConfirmButton: false
    });
}

// Função para exportar CSV
function exportarMensalidadesCSV() {
    const tabela = document.querySelector('.table');
    if (!tabela) {
        alert('Nenhum dado para exportar');
        return;
    }
    
    let csv = "\uFEFF"; // BOM para UTF-8
    
    // Cabeçalho
    csv += "Data;Tipo;Descrição;Valor;Forma de Pagamento;Fatura;Status\n";
    
    // Dados
    const rows = tabela.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 7) {
            const linha = [
                cells[0]?.innerText || '',
                cells[1]?.innerText || '',
                cells[2]?.innerText || '',
                cells[3]?.innerText || '',
                cells[4]?.innerText || '',
                cells[5]?.innerText || '',
                cells[6]?.innerText || ''
            ].join(';');
            csv += linha + '\n';
        }
    });
    
    // Download
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.setAttribute('download', `relatorio_pagamentos_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    
    Swal.fire({
        icon: 'success',
        title: 'CSV Gerado!',
        text: 'O download foi iniciado com sucesso',
        timer: 2000,
        showConfirmButton: false
    });
}

// Função para exportar PDF
// Exportar PDF (imprimir a página atual)
// Função para exportar PDF das mensalidades
function exportarMensalidadesPDF() {
    // Abrir nova janela com o PDF
    window.open('exportar_pagamentos_pdf.php', '_blank', 'width=900,height=700,toolbar=yes,scrollbars=yes,resizable=yes');
}

// Inicializar tooltips
document.addEventListener('DOMContentLoaded', function() {
    // Garantir que o dropdown do Bootstrap funcione
    const dropdownToggle = document.querySelector('[data-bs-toggle="dropdown"]');
    if (dropdownToggle && typeof bootstrap !== 'undefined') {
        new bootstrap.Dropdown(dropdownToggle);
    }
});
</script>
 
 <script>
// Função para adicionar efeito ripple nos cards
function adicionarEfeitoRipple() {
    const cards = document.querySelectorAll('.stat-card');
    cards.forEach(card => {
        card.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            ripple.className = 'ripple-effect';
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.width = ripple.style.height = `${size}px`;
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            
            this.style.position = 'relative';
            this.style.overflow = 'hidden';
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
        });
    });
}

// Função para animar contagem dos números
function animarContagem() {
    const valores = document.querySelectorAll('.stat-value');
    valores.forEach(valor => {
        const texto = valor.textContent;
        const valorNumerico = parseFloat(texto.replace(/[^0-9.-]+/g, ''));
        if (!isNaN(valorNumerico) && valorNumerico > 0) {
            let start = 0;
            const incremento = valorNumerico / 30;
            let current = start;
            const isMoeda = texto.includes('Kz');
            
            const timer = setInterval(() => {
                current += incremento;
                if (current >= valorNumerico) {
                    if (isMoeda) {
                        valor.textContent = valorNumerico.toLocaleString('pt-AO', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Kz';
                    } else {
                        valor.textContent = Math.floor(valorNumerico).toLocaleString('pt-AO');
                    }
                    clearInterval(timer);
                } else {
                    if (isMoeda) {
                        valor.textContent = Math.floor(current).toLocaleString('pt-AO') + ' Kz';
                    } else {
                        valor.textContent = Math.floor(current).toLocaleString('pt-AO');
                    }
                }
            }, 30);
        }
    });
}

// Adicionar tooltips nos cards
function adicionarTooltips() {
    const cards = document.querySelectorAll('.stat-card');
    const tooltips = [
        'Total de pagamentos realizados',
        'Valor total pago em todas as mensalidades',
        'Total de pagamentos em atraso',
        'Percentual de pagamentos em dia'
    ];
    
    cards.forEach((card, index) => {
        card.setAttribute('title', tooltips[index]);
        card.style.cursor = 'pointer';
    });
}

// Inicializar todas as melhorias
document.addEventListener('DOMContentLoaded', function() {
    adicionarEfeitoRipple();
    animarContagem();
    adicionarTooltips();
    
    // Adicionar ícones nos cards de estatísticas
    const cards = document.querySelectorAll('.stat-card');
    const icones = [
        '<i class="fas fa-receipt stat-icon"></i>',
        '<i class="fas fa-money-bill-wave stat-icon"></i>',
        '<i class="fas fa-exclamation-triangle stat-icon"></i>',
        '<i class="fas fa-chart-line stat-icon"></i>'
    ];
    
    cards.forEach((card, index) => {
        if (!card.querySelector('.stat-icon')) {
            card.insertAdjacentHTML('afterbegin', icones[index]);
        }
    });
});
</script>
  
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