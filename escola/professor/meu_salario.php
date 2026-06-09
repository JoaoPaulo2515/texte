<?php
// escola/professor/meu_salario.php - Meu Salário

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR DADOS DO PROFESSOR
// ============================================
$sql_professor = "
    SELECT p.*, u.email, u.nome 
    FROM professores p
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.id = :professor_id
";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':professor_id' => $professor_id]);
$professor_dados = $stmt_professor->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ID DO FUNCIONÁRIO
// ============================================
$sql_funcionario_id = "
    SELECT f.id 
    FROM funcionarios f
    INNER JOIN professores p ON p.usuario_id = f.usuario_id
    WHERE p.id = :professor_id
";
$stmt_func_id = $conn->prepare($sql_funcionario_id);
$stmt_func_id->execute([':professor_id' => $professor_id]);
$funcionario = $stmt_func_id->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario['id'] ?? $professor_id;

// ============================================
// BUSCAR INFORMAÇÕES SALARIAIS
// ============================================
$mes_atual_num = (int)date('m');
$ano_atual = (int)date('Y');

$sql_folha = "
    SELECT 
        fpf.*,
        COALESCE(fpf.salario_base, 0) as salario_base,
        COALESCE(fpf.subsidio_transporte, 0) as subsidio_transporte,
        COALESCE(fpf.subsidio_alimentacao, 0) as subsidio_alimentacao,
        COALESCE(fpf.outros_vencimentos, 0) as outros_vencimentos,
        COALESCE(fpf.total_vencimentos, 0) as total_vencimentos,
        COALESCE(fpf.faltas_valor, 0) as faltas_valor,
        COALESCE(fpf.horas_extras_valor, 0) as horas_extras_valor,
        COALESCE(fpf.outros_descontos, 0) as outros_descontos,
        COALESCE(fpf.total_descontos, 0) as total_descontos,
        COALESCE(fpf.salario_liquido, 0) as salario_liquido,
        COALESCE(fpf.gratificacao, 0) as gratificacao,
        COALESCE(fpf.seguro_saude, 0) as seguro_saude,
        COALESCE(fpf.desconto_irps, 0) as desconto_irps,
        COALESCE(fpf.desconto_atrasos, 0) as desconto_atrasos,
        COALESCE(fpf.desconto_emprestimo, 0) as desconto_emprestimo,
        COALESCE(fpf.desconto_seguranca_social, 0) as desconto_seguranca_social,
        fpf.mes_competencia,
        fpf.ano_competencia,
        fpf.data_processamento,
        fpf.status,
        fpf.observacoes
    FROM folha_processamento_funcionarios fpf
    WHERE fpf.funcionario_id = :funcionario_id
    AND fpf.mes_competencia = :mes_competencia
    AND fpf.ano_competencia = :ano_competencia
    ORDER BY fpf.id DESC
    LIMIT 1
";

$stmt_folha = $conn->prepare($sql_folha);
$stmt_folha->execute([
    ':funcionario_id' => $funcionario_id,
    ':mes_competencia' => $mes_atual_num,
    ':ano_competencia' => $ano_atual
]);
$salario = $stmt_folha->fetch(PDO::FETCH_ASSOC);

// Se não houver registro para o mês atual, buscar o último processado
if (!$salario) {
    $sql_ultimo = "
        SELECT 
            fpf.*,
            COALESCE(fpf.salario_base, 0) as salario_base,
            COALESCE(fpf.subsidio_transporte, 0) as subsidio_transporte,
            COALESCE(fpf.subsidio_alimentacao, 0) as subsidio_alimentacao,
            COALESCE(fpf.outros_vencimentos, 0) as outros_vencimentos,
            COALESCE(fpf.total_vencimentos, 0) as total_vencimentos,
            COALESCE(fpf.faltas_valor, 0) as faltas_valor,
            COALESCE(fpf.horas_extras_valor, 0) as horas_extras_valor,
            COALESCE(fpf.outros_descontos, 0) as outros_descontos,
            COALESCE(fpf.total_descontos, 0) as total_descontos,
            COALESCE(fpf.salario_liquido, 0) as salario_liquido,
            COALESCE(fpf.gratificacao, 0) as gratificacao,
            COALESCE(fpf.seguro_saude, 0) as seguro_saude,
            COALESCE(fpf.desconto_irps, 0) as desconto_irps,
            COALESCE(fpf.desconto_atrasos, 0) as desconto_atrasos,
            COALESCE(fpf.desconto_emprestimo, 0) as desconto_emprestimo,
            COALESCE(fpf.desconto_seguranca_social, 0) as desconto_seguranca_social
        FROM folha_processamento_funcionarios fpf
        WHERE fpf.funcionario_id = :funcionario_id
        ORDER BY fpf.ano_competencia DESC, fpf.mes_competencia DESC
        LIMIT 1
    ";
    $stmt_ultimo = $conn->prepare($sql_ultimo);
    $stmt_ultimo->execute([':funcionario_id' => $funcionario_id]);
    $salario = $stmt_ultimo->fetch(PDO::FETCH_ASSOC);
}

// Se ainda não houver, criar array padrão
if (!$salario) {
    $salario = [
        'salario_base' => 0,
        'subsidio_transporte' => 0,
        'subsidio_alimentacao' => 0,
        'outros_vencimentos' => 0,
        'total_vencimentos' => 0,
        'gratificacao' => 0,
        'seguro_saude' => 0,
        'faltas_valor' => 0,
        'horas_extras_valor' => 0,
        'desconto_irps' => 0,
        'desconto_atrasos' => 0,
        'desconto_emprestimo' => 0,
        'desconto_seguranca_social' => 0,
        'outros_descontos' => 0,
        'total_descontos' => 0,
        'salario_liquido' => 0,
        'status' => 'pendente',
        'mes_competencia' => $mes_atual_num,
        'ano_competencia' => $ano_atual,
        'data_processamento' => date('Y-m-d')
    ];
}

// Calcular totais se necessário
if ($salario['total_vencimentos'] == 0) {
    $salario['total_vencimentos'] = $salario['salario_base'] + $salario['subsidio_transporte'] + $salario['subsidio_alimentacao'] + $salario['outros_vencimentos'] + $salario['gratificacao'] + $salario['seguro_saude'];
}
if ($salario['total_descontos'] == 0) {
    $salario['total_descontos'] = $salario['faltas_valor'] + $salario['desconto_irps'] + $salario['desconto_atrasos'] + $salario['desconto_emprestimo'] + $salario['desconto_seguranca_social'] + $salario['outros_descontos'];
}
if ($salario['salario_liquido'] == 0) {
    $salario['salario_liquido'] = $salario['total_vencimentos'] - $salario['total_descontos'];
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getStatusBadge($status) {
    $badges = [
        'pago' => '<span class="badge badge-pago"><i class="fas fa-check-circle"></i> Pago</span>',
        'aprovado' => '<span class="badge badge-aprovado"><i class="fas fa-thumbs-up"></i> Aprovado</span>',
        'processado' => '<span class="badge badge-processado"><i class="fas fa-calculator"></i> Processado</span>',
        'pendente' => '<span class="badge badge-pendente"><i class="fas fa-clock"></i> Pendente</span>',
        'cancelado' => '<span class="badge badge-cancelado"><i class="fas fa-ban"></i> Cancelado</span>',
        'default '=> '<span class="badge badge-secondary">Indefinido</span>'
    ];
    return $badges[$status] ?? $badges['default'];
}

function formatarData($data) {
    if (empty($data) || $data == '0000-00-00') return '-';
    return date('d/m/Y', strtotime($data));
}

function getMesExtenso($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[(int)$mes];
}

$mes_referencia = $salario['mes_competencia'] ?? $mes_atual_num;
$ano_referencia = $salario['ano_competencia'] ?? $ano_atual;
$mes_atual = getMesExtenso($mes_referencia);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Meu Salário | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E VARIÁVEIS
        ============================================ */
        :root {
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --primary-dark: #1A2A6C;
            --primary-green: #006B3E;
            --secondary-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --gray-bg: #f8f9fc;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 15px 50px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        /* ============================================
           PAGE HEADER
        ============================================ */
        .page-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }

        /* Botões */
        .btn-voltar, .btn-pdf, .btn-print {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-pdf {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }

        .btn-pdf:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
            color: white;
        }

        .btn-print {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            color: white;
        }

        /* ============================================
           SALARY CARD
        ============================================ */
        .salary-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            margin-bottom: 25px;
        }

        .salary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .salary-header {
            background: var(--primary-gradient);
            padding: 30px 20px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .salary-header::before {
            content: '💰';
            position: absolute;
            bottom: -20px;
            right: -20px;
            font-size: 120px;
            opacity: 0.1;
        }

        .salary-label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .salary-amount {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 10px;
            letter-spacing: -1px;
        }

        /* Badges melhorados */
        .badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .badge-pago {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .badge-aprovado {
            background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%);
            color: white;
        }

        .badge-processado {
            background: linear-gradient(135deg, #006B3E 0%, #28a745 100%);
            color: white;
        }

        .badge-pendente {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: #212529;
        }

        .badge-cancelado {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }

        /* ============================================
           STATS CARDS
        ============================================ */
        .stats-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stats-card small {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ============================================
           INFO CARDS
        ============================================ */
        .info-card {
            background: white;
            border-radius: 24px;
            padding: 0;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            overflow: hidden;
        }

        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .info-title {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 18px 24px;
            font-weight: 700;
            color: var(--primary-green);
            border-bottom: 2px solid var(--primary-green);
            font-size: 1.1rem;
        }

        .info-title i {
            margin-right: 10px;
            color: var(--primary-green);
        }

        .info-body {
            padding: 20px 24px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
            transition: var(--transition);
        }

        .info-row:hover {
            background: #f8f9fa;
            padding-left: 10px;
            padding-right: 10px;
            border-radius: 8px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
        }

        .info-value {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .info-value.positive {
            color: var(--secondary-color);
        }

        .info-value.negative {
            color: var(--danger-color);
        }

        .total-row {
            margin-top: 10px;
            padding-top: 15px;
            border-top: 2px dashed #dee2e6;
        }

        .total-row .info-label,
        .total-row .info-value {
            font-size: 1rem;
            color: var(--primary-dark);
        }

        /* ============================================
           RESUMO CARD
        ============================================ */
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 24px;
            padding: 25px;
            color: white;
        }

        .progress-bar-custom {
            height: 8px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }

        .progress-bar {
            background: white;
            border-radius: 10px;
            transition: width 1s ease;
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

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        /* Delay classes */
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .salary-amount {
                font-size: 2rem;
            }
            
            .stats-number {
                font-size: 1.5rem;
            }
            
            .info-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .info-row:hover {
                padding-left: 0;
                padding-right: 0;
            }
            
            .btn-voltar, .btn-pdf, .btn-print {
                padding: 8px 16px;
                font-size: 0.75rem;
            }
            
            .page-header h2 {
                font-size: 1.3rem;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print, .main-content > .page-header .no-print, 
            .btn-voltar, .btn-pdf, .btn-print,
            .info-card .btn, button, .badge {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0;
                padding: 0;
            }
            
            .page-header {
                background: #006B3E !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .info-card, .salary-card, .stats-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            body {
                background: white;
            }
        }

        /* ============================================
           TOOLTIPS E EFEITOS ADICIONAIS
        ============================================ */
        .hover-scale {
            transition: var(--transition);
        }
        
        .hover-scale:hover {
            transform: scale(1.02);
        }

        .icon-circle {
            width: 40px;
            height: 40px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
        }

        /* Scrollbar personalizada */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-money-bill-wave me-2"></i> Meu Salário</h2>
                    <p><i class="fas fa-calendar-alt me-2"></i>Informações sobre sua remuneração e benefícios</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Dashboard
                    </a>
                    <button onclick="gerarPDF()" class="btn-pdf">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button onclick="window.print()" class="btn-print">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Cards Principais -->
        <div class="row">
            <div class="col-md-4 slide-in-left delay-1">
                <div class="salary-card">
                    <div class="salary-header">
                        <div class="salary-label"><i class="fas fa-wallet"></i> Salário Líquido</div>
                        <div class="salary-amount">KZ <?php echo formatarMoeda($salario['salario_liquido']); ?></div>
                        <div class="salary-label mt-2">
                            <i class="fas fa-calendar-alt"></i> <?php echo $mes_atual . ' de ' . $ano_referencia; ?>
                        </div>
                        <div class="mt-3">
                            <?php echo getStatusBadge($salario['status'] ?? 'pendente'); ?>
                        </div>
                    </div>
                    <div class="card-body p-4 text-center">
                        <small class="text-muted">
                            <i class="fas fa-clock"></i> Processado em: <?php echo formatarData($salario['data_processamento'] ?? date('Y-m-d')); ?>
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8 slide-in-right delay-1">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-success">KZ <?php echo formatarMoeda($salario['total_vencimentos']); ?></div>
                            <small><i class="fas fa-arrow-up"></i> Total de Vencimentos</small>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-danger">KZ <?php echo formatarMoeda($salario['total_descontos']); ?></div>
                            <small><i class="fas fa-arrow-down"></i> Total de Descontos</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Vencimentos e Descontos -->
        <div class="row">
            <div class="col-md-6 slide-in-left delay-2">
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-arrow-up text-success"></i> Vencimentos
                    </div>
                    <div class="info-body">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-coins me-2"></i>Salário Base</span>
                            <span class="info-value positive">KZ <?php echo formatarMoeda($salario['salario_base']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-bus me-2"></i>Subsídio de Transporte</span>
                            <span class="info-value positive">KZ <?php echo formatarMoeda($salario['subsidio_transporte']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-utensils me-2"></i>Subsídio de Alimentação</span>
                            <span class="info-value positive">KZ <?php echo formatarMoeda($salario['subsidio_alimentacao']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-star me-2"></i>Gratificação</span>
                            <span class="info-value positive">KZ <?php echo formatarMoeda($salario['gratificacao']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-heartbeat me-2"></i>Seguro Saúde</span>
                            <span class="info-value positive">KZ <?php echo formatarMoeda($salario['seguro_saude']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-clock me-2"></i>Horas Extras</span>
                            <span class="info-value positive">KZ <?php echo formatarMoeda($salario['horas_extras_valor']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-gift me-2"></i>Outros Vencimentos</span>
                            <span class="info-value positive">KZ <?php echo formatarMoeda($salario['outros_vencimentos']); ?></span>
                        </div>
                        <div class="info-row total-row">
                            <span class="info-label"><strong><i class="fas fa-chart-line"></i> TOTAL VENCIMENTOS</strong></span>
                            <span class="info-value positive"><strong>KZ <?php echo formatarMoeda($salario['total_vencimentos']); ?></strong></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 slide-in-right delay-2">
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-arrow-down text-danger"></i> Descontos
                    </div>
                    <div class="info-body">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-calendar-times me-2"></i>Faltas</span>
                            <span class="info-value negative">KZ <?php echo formatarMoeda($salario['faltas_valor']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-file-invoice-dollar me-2"></i>IRPS (Imposto)</span>
                            <span class="info-value negative">KZ <?php echo formatarMoeda($salario['desconto_irps']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-shield-alt me-2"></i>Segurança Social</span>
                            <span class="info-value negative">KZ <?php echo formatarMoeda($salario['desconto_seguranca_social']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-hourglass-half me-2"></i>Atrasos</span>
                            <span class="info-value negative">KZ <?php echo formatarMoeda($salario['desconto_atrasos']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-hand-holding-usd me-2"></i>Empréstimo</span>
                            <span class="info-value negative">KZ <?php echo formatarMoeda($salario['desconto_emprestimo']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-minus-circle me-2"></i>Outros Descontos</span>
                            <span class="info-value negative">KZ <?php echo formatarMoeda($salario['outros_descontos']); ?></span>
                        </div>
                        <div class="info-row total-row">
                            <span class="info-label"><strong><i class="fas fa-chart-line"></i> TOTAL DESCONTOS</strong></span>
                            <span class="info-value negative"><strong>KZ <?php echo formatarMoeda($salario['total_descontos']); ?></strong></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Resumo do Salário -->
        <div class="info-card slide-in-up delay-3">
            <div class="info-title">
                <i class="fas fa-calculator"></i> Resumo do Salário Líquido
            </div>
            <div class="info-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between mb-3">
                            <span><i class="fas fa-arrow-up text-success me-2"></i>Total de Vencimentos</span>
                            <span class="text-success fw-bold">KZ <?php echo formatarMoeda($salario['total_vencimentos']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span><i class="fas fa-arrow-down text-danger me-2"></i>Total de Descontos</span>
                            <span class="text-danger fw-bold">KZ <?php echo formatarMoeda($salario['total_descontos']); ?></span>
                        </div>
                        <div class="progress-bar-custom mb-4">
                            <?php $percentual = $salario['total_vencimentos'] > 0 ? round(($salario['total_descontos'] / $salario['total_vencimentos']) * 100, 1) : 0; ?>
                            <div class="progress-bar" style="width: <?php echo $percentual; ?>%; background: linear-gradient(90deg, #dc3545, #ff6b6b);"></div>
                        </div>
                        <div class="d-flex justify-content-between pt-3 border-top">
                            <strong><i class="fas fa-money-bill-wave me-2"></i>Salário Líquido</strong>
                            <strong class="text-success fs-4">KZ <?php echo formatarMoeda($salario['salario_liquido']); ?></strong>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="alert alert-info mb-0 rounded-3">
                            <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                            <p class="mb-0 small">Este é o valor líquido a receber após todos os descontos aplicados conforme a legislação angolana.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Informações Complementares -->
        <div class="row">
            <div class="col-md-6 slide-in-left delay-4">
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-user-graduate"></i> Dados do Professor
                    </div>
                    <div class="info-body">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-user me-2"></i>Nome:</span>
                            <span class="info-value"><?php echo htmlspecialchars($professor_dados['nome'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-envelope me-2"></i>Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($professor_dados['email'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-calendar-check me-2"></i>Data Admissão:</span>
                            <span class="info-value"><?php echo formatarData($professor_dados['data_admissao'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-graduation-cap me-2"></i>Ano Letivo:</span>
                            <span class="info-value"><?php echo $ano_letivo_ano; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 slide-in-right delay-4">
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-school"></i> Instituição de Ensino
                    </div>
                    <div class="info-body">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-building me-2"></i>Escola:</span>
                            <span class="info-value"><?php echo htmlspecialchars($escola['nome'] ?? 'Não definida'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-map-marker-alt me-2"></i>Endereço:</span>
                            <span class="info-value"><?php echo htmlspecialchars($escola['endereco'] ?? 'Não informado'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-phone me-2"></i>Telefone:</span>
                            <span class="info-value"><?php echo htmlspecialchars($escola['telefone'] ?? 'Não informado'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-envelope me-2"></i>Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($escola['email'] ?? 'Não informado'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Rodapé -->
        <div class="text-center text-muted small mt-4 pt-3 border-top">
            <hr>
            <i class="fas fa-file-invoice-dollar me-2"></i> 
            Documento emitido eletronicamente pelo SIGE Angola em <?php echo date('d/m/Y H:i:s'); ?>
            <br>
            <small><i class="fas fa-lock me-1"></i> Documento com validade legal - Sistema Integrado de Gestão Escolar</small>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function gerarPDF() {
            window.open('gerar_pdf_salario.php', '_blank');
        }
        
        // Adicionar animações ao scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            // Observar elementos com classes de animação
            document.querySelectorAll('.slide-in-left, .slide-in-right, .fade-in-up').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                observer.observe(el);
            });
        });
    </script>
</body>
</html>