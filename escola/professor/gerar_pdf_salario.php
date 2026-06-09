<?php
// escola/professor/gerar_pdf_salario.php - Gerar PDF do Salário

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
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
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
// PARÂMETROS DE FILTRO
// ============================================
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');

// ============================================
// BUSCAR ANOS DISPONÍVEIS (da folha_processamento_funcionarios)
// ============================================
$sql_anos = "SELECT DISTINCT ano_competencia 
             FROM folha_processamento_funcionarios 
             WHERE funcionario_id = :funcionario_id 
             ORDER BY ano_competencia DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':funcionario_id' => $funcionario_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [['ano_competencia' => date('Y')]];
}

// ============================================
// BUSCAR INFORMAÇÕES SALARIAIS
// ============================================
$sql_folha = "
    SELECT 
        fpf.*,
        COALESCE(fpf.salario_base, 0) as salario_base,
        COALESCE(fpf.subsidio_transporte, 0) as subsidio_transporte,
        COALESCE(fpf.subsidio_alimentacao, 0) as subsidio_alimentacao,
        COALESCE(fpf.outros_vencimentos, 0) as outros_vencimentos,
        COALESCE(fpf.total_vencimentos, 0) as total_vencimentos,
        COALESCE(fpf.gratificacao, 0) as gratificacao,
        COALESCE(fpf.seguro_saude, 0) as seguro_saude,
        COALESCE(fpf.faltas_valor, 0) as faltas_valor,
        COALESCE(fpf.horas_extras_valor, 0) as horas_extras_valor,
        COALESCE(fpf.desconto_irps, 0) as desconto_irps,
        COALESCE(fpf.desconto_atrasos, 0) as desconto_atrasos,
        COALESCE(fpf.desconto_emprestimo, 0) as desconto_emprestimo,
        COALESCE(fpf.desconto_seguranca_social, 0) as desconto_seguranca_social,
        COALESCE(fpf.outros_descontos, 0) as outros_descontos,
        COALESCE(fpf.total_descontos, 0) as total_descontos,
        COALESCE(fpf.salario_liquido, 0) as salario_liquido,
        fpf.mes_competencia,
        fpf.ano_competencia,
        fpf.data_processamento,
        fpf.status,
        fpf.observacoes,
        u.nome as processado_por_nome
    FROM folha_processamento_funcionarios fpf
    LEFT JOIN usuarios u ON u.id = fpf.processado_por
    WHERE fpf.funcionario_id = :funcionario_id
    AND fpf.mes_competencia = :mes_competencia
    AND fpf.ano_competencia = :ano_competencia
    ORDER BY fpf.id DESC
    LIMIT 1
";

$stmt_folha = $conn->prepare($sql_folha);
$stmt_folha->execute([
    ':funcionario_id' => $funcionario_id,
    ':mes_competencia' => $mes_filtro,
    ':ano_competencia' => $ano_filtro
]);
$salario = $stmt_folha->fetch(PDO::FETCH_ASSOC);

// Se não houver registro para o período selecionado, buscar o último processado
if (!$salario) {
    $sql_ultimo = "
        SELECT 
            fpf.*,
            COALESCE(fpf.salario_base, 0) as salario_base,
            COALESCE(fpf.subsidio_transporte, 0) as subsidio_transporte,
            COALESCE(fpf.subsidio_alimentacao, 0) as subsidio_alimentacao,
            COALESCE(fpf.outros_vencimentos, 0) as outros_vencimentos,
            COALESCE(fpf.total_vencimentos, 0) as total_vencimentos,
            COALESCE(fpf.gratificacao, 0) as gratificacao,
            COALESCE(fpf.seguro_saude, 0) as seguro_saude,
            COALESCE(fpf.faltas_valor, 0) as faltas_valor,
            COALESCE(fpf.horas_extras_valor, 0) as horas_extras_valor,
            COALESCE(fpf.desconto_irps, 0) as desconto_irps,
            COALESCE(fpf.desconto_atrasos, 0) as desconto_atrasos,
            COALESCE(fpf.desconto_emprestimo, 0) as desconto_emprestimo,
            COALESCE(fpf.desconto_seguranca_social, 0) as desconto_seguranca_social,
            COALESCE(fpf.outros_descontos, 0) as outros_descontos,
            COALESCE(fpf.total_descontos, 0) as total_descontos,
            COALESCE(fpf.salario_liquido, 0) as salario_liquido,
            fpf.mes_competencia,
            fpf.ano_competencia,
            fpf.data_processamento,
            fpf.status,
            fpf.observacoes,
            u.nome as processado_por_nome
        FROM folha_processamento_funcionarios fpf
        LEFT JOIN usuarios u ON u.id = fpf.processado_por
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
        'mes_competencia' => $mes_filtro,
        'ano_competencia' => $ano_filtro,
        'data_processamento' => date('Y-m-d H:i:s'),
        'observacoes' => 'Aguardando processamento da folha de pagamento',
        'processado_por_nome' => 'Sistema'
    ];
}

// Calcular totais se necessário
if ($salario['total_vencimentos'] == 0) {
    $salario['total_vencimentos'] = $salario['salario_base'] + $salario['subsidio_transporte'] + 
                                     $salario['subsidio_alimentacao'] + $salario['outros_vencimentos'] + 
                                     $salario['gratificacao'] + $salario['seguro_saude'] + 
                                     $salario['horas_extras_valor'];
}
if ($salario['total_descontos'] == 0) {
    $salario['total_descontos'] = $salario['faltas_valor'] + $salario['desconto_irps'] + 
                                   $salario['desconto_atrasos'] + $salario['desconto_emprestimo'] + 
                                   $salario['desconto_seguranca_social'] + $salario['outros_descontos'];
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
    switch ($status) {
        case 'pago':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> PAGO</span>';
        case 'aprovado':
            return '<span class="badge bg-info"><i class="fas fa-thumbs-up"></i> APROVADO</span>';
        case 'processado':
            return '<span class="badge bg-primary"><i class="fas fa-calculator"></i> PROCESSADO</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> PENDENTE</span>';
        case 'cancelado':
            return '<span class="badge bg-danger"><i class="fas fa-ban"></i> CANCELADO</span>';
        default:
            return '<span class="badge bg-secondary">INDEFINIDO</span>';
    }
}

function getMesExtenso($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[(int)$mes];
}

function getNumeroExtenso($numero) {
    $numero = (int)$numero;
    $unidades = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];
    $dezenas = ['', 'dez', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $centenas = ['', 'cem', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    
    if ($numero == 0) return 'zero';
    if ($numero < 10) return $unidades[$numero];
    if ($numero < 100) {
        $d = floor($numero / 10);
        $u = $numero % 10;
        return $dezenas[$d] . ($u ? ' e ' . $unidades[$u] : '');
    }
    if ($numero < 1000) {
        $c = floor($numero / 100);
        $r = $numero % 100;
        return $centenas[$c] . ($r ? ' e ' . getNumeroExtenso($r) : '');
    }
    return number_format($numero, 0, ',', '.');
}

function valorPorExtenso($valor) {
    $inteiro = (int)$valor;
    $centavos = round(($valor - $inteiro) * 100);
    $extenso = getNumeroExtenso($inteiro) . ' kwanzas';
    if ($centavos > 0) {
        $extenso .= ' e ' . getNumeroExtenso($centavos) . ' cêntimos';
    }
    return ucfirst($extenso);
}

$mes_atual = getMesExtenso($salario['mes_competencia']);
$ano_atual = $salario['ano_competencia'];
$percentual_desconto = $salario['total_vencimentos'] > 0 ? 
                       round(($salario['total_descontos'] / $salario['total_vencimentos']) * 100, 1) : 0;
$percentual_liquido = $salario['total_vencimentos'] > 0 ? 
                      round(($salario['salario_liquido'] / $salario['total_vencimentos']) * 100, 1) : 0;

// Definir título da página
$titulo_pagina = 'Recibo de Vencimento - ' . $mes_atual . '/' . $ano_atual;
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Professor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        
        /* Container principal */
        .recibo-container {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            animation: fadeInUp 0.5s ease-out;
        }
        
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
        
        /* Cabeçalho */
        .recibo-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .recibo-header::before {
            content: '💰';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 150px;
            opacity: 0.1;
        }
        
        .logo-escola {
            max-height: 70px;
            margin-bottom: 15px;
        }
        
        .titulo-recibo {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: 2px;
        }
        
        .subtitulo-recibo {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        /* Informações do Professor */
        .info-professor {
            background: #f8f9fa;
            padding: 20px 30px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #dee2e6;
        }
        
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
            width: 150px;
        }
        
        .info-value {
            flex: 1;
            color: #212529;
            font-weight: 500;
        }
        
        /* Cards de Valores */
        .valor-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
            height: 100%;
        }
        
        .valor-card:hover {
            transform: translateY(-5px);
        }
        
        .valor-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .valor-amount {
            font-size: 2rem;
            font-weight: 800;
        }
        
        .valor-amount.positive {
            color: #28a745;
        }
        
        .valor-amount.negative {
            color: #dc3545;
        }
        
        .valor-amount.primary {
            color: #006B3E;
        }
        
        /* Tabelas */
        .table-salary {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-salary th, .table-salary td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table-salary th {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            font-weight: 600;
        }
        
        .table-salary tr:hover {
            background: #f8f9fa;
        }
        
        .table-salary td:last-child {
            text-align: right;
            font-weight: 600;
        }
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .total-row td {
            border-top: 2px solid #006B3E;
            border-bottom: 2px solid #006B3E;
        }
        
        /* Progress Bar */
        .progress-custom {
            height: 10px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .progress-bar-custom {
            height: 100%;
            border-radius: 10px;
            transition: width 1s ease;
        }
        
        .progress-bar-vencimentos {
            background: linear-gradient(90deg, #28a745, #20c997);
        }
        
        .progress-bar-descontos {
            background: linear-gradient(90deg, #dc3545, #ff6b6b);
        }
        
        /* Botões */
        .btn-actions {
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .btn-custom {
            padding: 10px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin: 0 5px;
            border: none;
        }
        
        .btn-pdf {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .btn-pdf:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }
        
        .btn-print {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
        }
        
        .btn-voltar:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        /* Filtros */
        .filtros-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        /* Rodapé */
        .recibo-footer {
            background: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            border-top: 1px solid #e0e0e0;
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        /* Assinaturas */
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #dee2e6;
        }
        
        .assinatura-item {
            text-align: center;
            flex: 1;
        }
        
        .linha-assinatura {
            width: 200px;
            height: 2px;
            background: #333;
            margin: 10px auto;
        }
        
        /* Responsividade */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .recibo-header {
                padding: 20px;
            }
            
            .titulo-recibo {
                font-size: 1.5rem;
            }
            
            .valor-amount {
                font-size: 1.3rem;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .assinaturas {
                flex-direction: column;
                gap: 20px;
            }
            
            .table-salary th, .table-salary td {
                padding: 8px 10px;
                font-size: 0.8rem;
            }
        }
        
        /* Impressão */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            
            .btn-actions, .filtros-card, .no-print {
                display: none !important;
            }
            
            .recibo-container {
                box-shadow: none;
                margin: 0;
                border-radius: 0;
            }
            
            .recibo-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .table-salary th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .assinatura-item {
                margin-top: 50px;
            }
            
            .linha-assinatura {
                background: #333;
            }
        }
        
        /* Badge Status */
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* Extenso */
        .valor-extenso {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 12px;
            font-size: 0.85rem;
            color: #2e7d32;
            margin-top: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="recibo-container">
        <!-- Cabeçalho -->
        <div class="recibo-header">
            <?php if (!empty($escola['logo']) && file_exists('../../uploads/escolas/logos/' . $escola['logo'])): ?>
                <img src="../../uploads/escolas/logos/<?php echo $escola['logo']; ?>" class="logo-escola" alt="Logo">
            <?php endif; ?>
            <h1 class="titulo-recibo">RECIBO DE VENCIMENTO</h1>
            <p class="subtitulo-recibo"><?php echo htmlspecialchars($escola['nome'] ?? 'SIGE Angola'); ?></p>
            <p class="subtitulo-recibo mt-2">
                <i class="fas fa-calendar-alt"></i> Competência: <?php echo $mes_atual . ' de ' . $ano_atual; ?>
            </p>
            <div class="mt-2">
                <?php echo getStatusBadge($salario['status'] ?? 'pendente'); ?>
            </div>
        </div>
        
        <!-- Informações do Professor -->
        <div class="info-professor">
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user me-2"></i>Funcionário:</span>
                        <span class="info-value"><?php echo htmlspecialchars($professor_dados['nome'] ?? ''); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-id-card me-2"></i>Nº Funcionário:</span>
                        <span class="info-value"><?php echo htmlspecialchars($professor_dados['numero_funcionario'] ?? $professor_id); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-envelope me-2"></i>Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($professor_dados['email'] ?? ''); ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-briefcase me-2"></i>Cargo:</span>
                        <span class="info-value">Professor</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-calendar-check me-2"></i>Data Admissão:</span>
                        <span class="info-value"><?php echo !empty($professor_dados['data_admissao']) ? date('d/m/Y', strtotime($professor_dados['data_admissao'])) : '-'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-building me-2"></i>Instituição:</span>
                        <span class="info-value"><?php echo htmlspecialchars($escola['nome'] ?? 'Não definida'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros (apenas para visualização, não imprime) -->
        <div class="filtros-card no-print">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold"><i class="fas fa-calendar"></i> Ano</label>
                    <select name="ano" class="form-select">
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano['ano_competencia']; ?>" <?php echo $ano_filtro == $ano['ano_competencia'] ? 'selected' : ''; ?>>
                            <?php echo $ano['ano_competencia']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold"><i class="fas fa-calendar-alt"></i> Mês</label>
                    <select name="mes" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $mes_filtro == $m ? 'selected' : ''; ?>>
                            <?php echo getMesExtenso($m); ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Visualizar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 p-4">
            <div class="col-md-4">
                <div class="valor-card">
                    <div class="valor-label"><i class="fas fa-arrow-up text-success"></i> Total Vencimentos</div>
                    <div class="valor-amount positive">KZ <?php echo formatarMoeda($salario['total_vencimentos']); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="valor-card">
                    <div class="valor-label"><i class="fas fa-arrow-down text-danger"></i> Total Descontos</div>
                    <div class="valor-amount negative">KZ <?php echo formatarMoeda($salario['total_descontos']); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="valor-card" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <div class="valor-label" style="color: rgba(255,255,255,0.8);"><i class="fas fa-wallet"></i> Salário Líquido</div>
                    <div class="valor-amount" style="color: white;">KZ <?php echo formatarMoeda($salario['salario_liquido']); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Progress Bar -->
        <div class="px-4 mb-4">
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">Vencimentos (<?php echo $percentual_liquido; ?>%)</small>
                    <div class="progress-custom">
                        <div class="progress-bar-custom progress-bar-vencimentos" style="width: <?php echo $percentual_liquido; ?>%"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">Descontos (<?php echo $percentual_desconto; ?>%)</small>
                    <div class="progress-custom">
                        <div class="progress-bar-custom progress-bar-descontos" style="width: <?php echo $percentual_desconto; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Vencimentos -->
        <div class="row g-0">
            <div class="col-md-6 p-4">
                <h5 class="mb-3 text-success"><i class="fas fa-arrow-up"></i> VENCIMENTOS</h5>
                <table class="table-salary">
                    <thead>
                        <tr><th>Descrição</th><th>Valor (KZ)</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Salário Base</td><td><?php echo formatarMoeda($salario['salario_base']); ?></td></tr>
                        <tr><td>Subsídio de Transporte</td><td><?php echo formatarMoeda($salario['subsidio_transporte']); ?></td></tr>
                        <tr><td>Subsídio de Alimentação</td><td><?php echo formatarMoeda($salario['subsidio_alimentacao']); ?></td></tr>
                        <tr><td>Gratificação</td><td><?php echo formatarMoeda($salario['gratificacao']); ?></td></tr>
                        <tr><td>Seguro Saúde</td><td><?php echo formatarMoeda($salario['seguro_saude']); ?></td></tr>
                        <tr><td>Horas Extras</td><td><?php echo formatarMoeda($salario['horas_extras_valor']); ?></td></tr>
                        <tr><td>Outros Vencimentos</td><td><?php echo formatarMoeda($salario['outros_vencimentos']); ?></td></tr>
                        <tr class="total-row"><td><strong>TOTAL VENCIMENTOS</strong></td><td><strong><?php echo formatarMoeda($salario['total_vencimentos']); ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Tabela de Descontos -->
            <div class="col-md-6 p-4">
                <h5 class="mb-3 text-danger"><i class="fas fa-arrow-down"></i> DESCONTOS</h5>
                <table class="table-salary">
                    <thead>
                        <tr><th>Descrição</th><th>Valor (KZ)</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Faltas</td><td class="text-danger"><?php echo formatarMoeda($salario['faltas_valor']); ?></td></tr>
                        <tr><td>IRPS (Imposto)</td><td class="text-danger"><?php echo formatarMoeda($salario['desconto_irps']); ?></td></tr>
                        <tr><td>Segurança Social</td><td class="text-danger"><?php echo formatarMoeda($salario['desconto_seguranca_social']); ?></td></tr>
                        <tr><td>Atrasos</td><td class="text-danger"><?php echo formatarMoeda($salario['desconto_atrasos']); ?></td></tr>
                        <tr><td>Empréstimo</td><td class="text-danger"><?php echo formatarMoeda($salario['desconto_emprestimo']); ?></td></tr>
                        <tr><td>Outros Descontos</td><td class="text-danger"><?php echo formatarMoeda($salario['outros_descontos']); ?></td></tr>
                        <tr class="total-row"><td><strong>TOTAL DESCONTOS</strong></td><td class="text-danger"><strong><?php echo formatarMoeda($salario['total_descontos']); ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Valor por Extenso -->
        <div class="px-4">
            <div class="valor-extenso">
                <i class="fas fa-quote-left me-2"></i> 
                <?php echo valorPorExtenso($salario['salario_liquido']); ?>
                <i class="fas fa-quote-right ms-2"></i>
            </div>
        </div>
        
        <!-- Observações -->
        <?php if (!empty($salario['observacoes'])): ?>
        <div class="px-4 mt-3">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Observações:</strong> <?php echo htmlspecialchars($salario['observacoes']); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Informações Adicionais -->
        <div class="px-4 mt-3">
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        <i class="fas fa-clock"></i> Data de Processamento: 
                        <?php echo !empty($salario['data_processamento']) ? date('d/m/Y H:i:s', strtotime($salario['data_processamento'])) : '-'; ?>
                    </small>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted">
                        <i class="fas fa-user-check"></i> Processado por: 
                        <?php echo htmlspecialchars($salario['processado_por_nome'] ?? 'Sistema'); ?>
                    </small>
                </div>
            </div>
        </div>
        
        <!-- Assinaturas -->
        <div class="assinaturas px-4 mb-4">
            <div class="assinatura-item">
                <div class="linha-assinatura"></div>
                <small>Funcionário</small>
            </div>
            <div class="assinatura-item">
                <div class="linha-assinatura"></div>
                <small>Direção Pedagógica</small>
            </div>
            <div class="assinatura-item">
                <div class="linha-assinatura"></div>
                <small>Administração</small>
            </div>
        </div>
        
        <!-- Rodapé -->
        <div class="recibo-footer">
            <p>
                <i class="fas fa-file-invoice-dollar"></i> Documento emitido eletronicamente pelo SIGE Angola - Sistema Integrado de Gestão Escolar<br>
                <small>Este documento tem validade legal conforme legislação em vigor. Emitido em <?php echo date('d/m/Y H:i:s'); ?></small>
            </p>
        </div>
        
        <!-- Botões de Ação -->
        <div class="btn-actions no-print">
            <button onclick="" class="btn-custom btn-print">
                <i class="fas fa-print"></i><a href="gerar_pdf_salario_imprimir.php?mes=<?php echo $mes_filtro; ?>&ano=<?php echo $ano_filtro; ?>" target="_blank">
    Gerar PDF
</a>
            </button>
            <button onclick="window.close()" class="btn-custom btn-voltar">
                <i class="fas fa-times"></i> Fechar
            </button>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Animação das progress bars
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.progress-bar-custom');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
    </script>
</body>
</html>