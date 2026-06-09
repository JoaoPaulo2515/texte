<?php
// escola/professor/dividas_receber.php - Dívidas a Receber

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR FUNCIONARIO_ID DO PROFESSOR LOGADO
// ============================================
$sql_funcionario = "
    SELECT f.id as funcionario_id, f.nome, f.cargo, f.salario_base
    FROM funcionarios f
    INNER JOIN professores p ON p.usuario_id = f.usuario_id
    WHERE p.id = :professor_id
";
$stmt_funcionario = $conn->prepare($sql_funcionario);
$stmt_funcionario->execute([':professor_id' => $professor_id]);
$funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario['funcionario_id'] ?? $professor_id;
$funcionario_nome = $funcionario['nome'] ?? '';
$funcionario_salario = $funcionario['salario_base'] ?? 0;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// PROCESSAR RECEBIMENTO MANUAL
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_recebimento'])) {
    $divida_id = (int)$_POST['divida_id'];
    $valor_recebido = (float)$_POST['valor_recebido'];
    $data_recebimento = $_POST['data_recebimento'];
    $forma_recebimento = $_POST['forma_recebimento'];
    $observacao = $_POST['observacao'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        $sql_divida = "SELECT * FROM dividas_a_receber WHERE id = :id AND funcionario_id = :funcionario_id";
        $stmt_divida = $conn->prepare($sql_divida);
        $stmt_divida->execute([':id' => $divida_id, ':funcionario_id' => $funcionario_id]);
        $divida = $stmt_divida->fetch(PDO::FETCH_ASSOC);
        
        if ($divida) {
            $sql_hist = "
                INSERT INTO recebimentos_historico (
                    divida_id, funcionario_id, valor_recebido, data_recebimento, forma_recebimento, observacao, created_at
                ) VALUES (
                    :divida_id, :funcionario_id, :valor_recebido, :data_recebimento, :forma_recebimento, :observacao, NOW()
                )
            ";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([
                ':divida_id' => $divida_id,
                ':funcionario_id' => $funcionario_id,
                ':valor_recebido' => $valor_recebido,
                ':data_recebimento' => $data_recebimento,
                ':forma_recebimento' => $forma_recebimento,
                ':observacao' => $observacao
            ]);
            
            $success = "✅ Recebimento registrado com sucesso! O status foi atualizado automaticamente.";
        }
        
        $conn->commit();
        header("Location: dividas_receber.php?success=1");
        exit;
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Erro ao processar recebimento: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR DÍVIDAS A RECEBER
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todas';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

$sql_dividas = "
    SELECT 
        d.*,
        COALESCE(d.valor_original, 0) as valor_original,
        COALESCE(d.valor_recebido, 0) as valor_recebido,
        (d.valor_original - COALESCE(d.valor_recebido, 0)) as valor_restante,
        COALESCE(d.juros, 0) as juros,
        COALESCE(d.multas, 0) as multas,
        COALESCE(d.desconto, 0) as desconto,
        COALESCE(d.gerado_automaticamente, 0) as gerado_automaticamente
    FROM dividas_a_receber d
    WHERE d.funcionario_id = :funcionario_id
";

if ($status_filtro != 'todas') {
    $sql_dividas .= " AND d.status = :status";
}
if ($tipo_filtro != 'todos') {
    $sql_dividas .= " AND d.tipo = :tipo";
}
if (!empty($busca)) {
    $sql_dividas .= " AND (d.descricao LIKE :busca OR d.referencia LIKE :busca OR d.devedor_nome LIKE :busca)";
}

$sql_dividas .= " ORDER BY 
    CASE 
        WHEN d.status = 'vencido' THEN 0 
        WHEN d.status = 'pendente' AND d.data_vencimento < CURDATE() THEN 1
        WHEN d.status = 'pendente' THEN 2
        WHEN d.status = 'parcial' THEN 3
        ELSE 4 
    END,
    d.data_vencimento ASC, 
    d.created_at DESC";

$stmt_dividas = $conn->prepare($sql_dividas);
$params = [':funcionario_id' => $funcionario_id];
if ($status_filtro != 'todas') {
    $params[':status'] = $status_filtro;
}
if ($tipo_filtro != 'todos') {
    $params[':tipo'] = $tipo_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = '%' . $busca . '%';
}
$stmt_dividas->execute($params);
$dividas = $stmt_dividas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_dividas = count($dividas);
$total_valor_a_receber = 0;
$total_valor_recebido = 0;
$total_vencidas = 0;
$total_parciais = 0;
$total_geradas_auto = 0;

foreach ($dividas as $divida) {
    $total_valor_a_receber += $divida['valor_restante'];
    $total_valor_recebido += $divida['valor_recebido'];
    
    if ($divida['status'] == 'parcial') $total_parciais++;
    if ($divida['gerado_automaticamente'] == 1) $total_geradas_auto++;
    
    if ($divida['data_vencimento'] < date('Y-m-d') && $divida['status'] != 'recebido') {
        $total_vencidas++;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) { return number_format($valor, 2, ',', '.'); }
function formatarData($data) { return empty($data) ? '-' : date('d/m/Y', strtotime($data)); }

function getStatusBadge($status) {
    switch ($status) {
        case 'recebido': return '<span class="badge badge-recebido"><i class="fas fa-check-circle"></i> Recebido</span>';
        case 'pendente': return '<span class="badge badge-pendente"><i class="fas fa-clock"></i> Pendente</span>';
        case 'vencido': return '<span class="badge badge-vencido"><i class="fas fa-exclamation-triangle"></i> Vencido</span>';
        case 'parcial': return '<span class="badge badge-parcial"><i class="fas fa-chart-line"></i> Parcial</span>';
        default: return '<span class="badge badge-secondary">' . $status . '</span>';
    }
}

function getTipoBadge($tipo) {
    $tipos = [
        'emprestimo' => '<span class="badge badge-emprestimo"><i class="fas fa-hand-holding-usd"></i> Empréstimo</span>',
        'beneficio' => '<span class="badge badge-beneficio"><i class="fas fa-gift"></i> Benefício</span>',
        'taxa' => '<span class="badge badge-taxa"><i class="fas fa-percent"></i> Taxa</span>',
        'multa' => '<span class="badge badge-multa"><i class="fas fa-gavel"></i> Multa</span>',
        'mensalidade' => '<span class="badge badge-mensalidade"><i class="fas fa-calendar-alt"></i> Mensalidade</span>',
        'ressarcimento' => '<span class="badge badge-ressarcimento"><i class="fas fa-hand-holding-heart"></i> Ressarcimento</span>'
    ];
    return $tipos[$tipo] ?? '<span class="badge badge-secondary">' . $tipo . '</span>';
}

function getSituacaoVencimento($data_vencimento) {
    if (empty($data_vencimento)) return '';
    $hoje = date('Y-m-d');
    if ($data_vencimento < $hoje) {
        $dias = ceil((strtotime($hoje) - strtotime($data_vencimento)) / (60 * 60 * 24));
        return '<span class="text-vencido"><i class="fas fa-exclamation-circle"></i> Vencida há ' . $dias . ' dias</span>';
    } elseif ($data_vencimento == $hoje) {
        return '<span class="text-warning"><i class="fas fa-bell"></i> Vence hoje</span>';
    } else {
        $dias = ceil((strtotime($data_vencimento) - strtotime($hoje)) / (60 * 60 * 24));
        return '<span class="text-muted"><i class="fas fa-calendar-day"></i> Vence em ' . $dias . ' dias</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Dívidas a Receber | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E VARIÁVEIS
        ============================================ */
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
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
            content: '💰';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 120px;
            opacity: 0.1;
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

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar, .btn-ajuda, .btn-print {
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

        .btn-ajuda {
            background: linear-gradient(135deg, #fd7e14 0%, #e66a00 100%);
            color: white;
        }

        .btn-ajuda:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 126, 20, 0.3);
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
           ALERTAS
        ============================================ */
        .alert-auto {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-left: 4px solid var(--success);
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .alert-auto::before {
            content: '🤖';
            position: absolute;
            right: 10px;
            bottom: 10px;
            font-size: 40px;
            opacity: 0.2;
        }

        /* ============================================
           STATS CARDS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
        }

        .stat-card.danger .stat-number { color: var(--danger); }
        .stat-card.success .stat-number { color: var(--success); }
        .stat-card.info .stat-number { color: var(--info); }
        .stat-card.warning .stat-number { color: var(--warning); }
        .stat-card.primary .stat-number { color: var(--primary-green); }

        /* ============================================
           FILTER CARD
        ============================================ */
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }

        .filter-card .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            transition: var(--transition);
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
        }

        /* ============================================
           DIVIDA CARD
        ============================================ */
        .dividas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
        }

        .divida-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
        }

        .divida-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .divida-card.vencido { border-left: 4px solid var(--danger); background: linear-gradient(135deg, #fff5f5 0%, #fff 100%); }
        .divida-card.pendente { border-left: 4px solid var(--warning); }
        .divida-card.recebido { border-left: 4px solid var(--success); opacity: 0.85; }
        .divida-card.parcial { border-left: 4px solid var(--info); background: linear-gradient(135deg, #e8f4f8 0%, #fff 100%); }

        .divida-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .divida-body {
            padding: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #e9ecef;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.8rem;
        }

        .info-value {
            font-weight: 500;
            color: #212529;
        }

        .info-value.text-danger {
            color: var(--danger);
            font-weight: 700;
        }

        .info-value.text-success {
            color: var(--success);
            font-weight: 700;
        }

        /* Progress Bar */
        .progress-recebimento {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
            margin: 15px 0;
        }

        .progress-bar {
            height: 100%;
            border-radius: 10px;
            transition: width 1s ease;
        }

        /* Badges */
        .badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-recebido { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .badge-pendente { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-vencido { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .badge-parcial { background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%); color: white; }
        .badge-emprestimo { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .badge-beneficio { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .badge-taxa { background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%); color: white; }
        .badge-multa { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .badge-mensalidade { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-ressarcimento { background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%); color: white; }
        .badge-secondary { background: #6c757d; color: white; }

        .badge-auto {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Botões de ação */
        .btn-receber {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
        }

        .btn-receber:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(40, 167, 69, 0.3);
            color: white;
        }

        .btn-detalhes {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
        }

        .btn-detalhes:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(23, 162, 184, 0.3);
            color: white;
        }

        /* ============================================
           MODAL
        ============================================ */
        .modal-header-custom {
            background: var(--primary-gradient);
            color: white;
        }

        .modal-header-custom .btn-close-white {
            filter: brightness(0) invert(1);
        }

        /* ============================================
           HELP SECTION
        ============================================ */
        .help-step {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 16px;
            transition: var(--transition);
        }

        .help-step:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .help-number {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .help-content {
            flex: 1;
        }

        .help-content h6 {
            color: var(--primary-green);
            margin-bottom: 5px;
            font-weight: 700;
        }

        .help-content p {
            margin-bottom: 0;
            font-size: 0.85rem;
            color: #6c757d;
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

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        /* ============================================
           SCROLLBAR PERSONALIZADA
        ============================================ */
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

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .dividas-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .info-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .divida-header .d-flex {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }
            
            .divida-body .d-flex.justify-content-between {
                flex-direction: column;
                gap: 10px;
            }
            
            .btn-receber, .btn-detalhes {
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header h2 {
                font-size: 1.3rem;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print, .filter-card, .btn-ajuda, .btn-print, .btn-voltar {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .page-header {
                background: #006B3E !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .divida-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .stat-card {
                break-inside: avoid;
            }
        }

        /* ============================================
           TEXTOS DE STATUS
        ============================================ */
        .text-vencido {
            color: var(--danger);
            font-weight: 600;
        }

        .text-warning {
            color: var(--warning);
            font-weight: 600;
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
                    <h2><i class="fas fa-hand-holding-heart me-2"></i> Dívidas a Receber</h2>
                    <p>Gerencie os valores que você tem a receber - Sistema Automático de Geração</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <button type="button" class="btn-ajuda" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                        <i class="fas fa-question-circle"></i> Como Funciona
                    </button>
                    <button onclick="window.print()" class="btn-print">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Alerta de Processamento Automático -->
        <div class="alert-auto fade-in-up">
            <i class="fas fa-robot text-success me-2"></i> 
            <strong>🤖 Sistema Automático de Gestão de Créditos</strong><br>
            As dívidas são geradas automaticamente ao cadastrar funcionários e ao iniciar um novo ano letivo. 
            Os status são atualizados em tempo real conforme os recebimentos são registrados.
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card slide-in-left delay-1">
                <div class="stat-number"><?php echo $total_dividas; ?></div>
                <div class="stat-label">Total de Créditos</div>
            </div>
            <div class="stat-card success slide-in-left delay-2">
                <div class="stat-number">KZ <?php echo formatarMoeda($total_valor_a_receber); ?></div>
                <div class="stat-label">Valor a Receber</div>
            </div>
            <div class="stat-card info slide-in-right delay-1">
                <div class="stat-number">KZ <?php echo formatarMoeda($total_valor_recebido); ?></div>
                <div class="stat-label">Valor Recebido</div>
            </div>
            <div class="stat-card danger slide-in-right delay-2">
                <div class="stat-number"><?php echo $total_vencidas; ?></div>
                <div class="stat-label">Créditos Vencidos</div>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card primary slide-in-left delay-3">
                <div class="stat-number"><?php echo $total_parciais; ?></div>
                <div class="stat-label">Recebimentos Parciais</div>
            </div>
            <div class="stat-card slide-in-left delay-4">
                <div class="stat-number"><?php echo $total_geradas_auto; ?></div>
                <div class="stat-label">Geradas Automaticamente</div>
            </div>
            <div class="stat-card info slide-in-right delay-3">
                <div class="stat-number"><?php echo $total_dividas - $total_vencidas - $total_parciais; ?></div>
                <div class="stat-label">Em Dia</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card no-print fade-in-up">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-filter me-1"></i> Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?php echo $status_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                        <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                        <option value="vencido" <?php echo $status_filtro == 'vencido' ? 'selected' : ''; ?>>Vencidas</option>
                        <option value="recebido" <?php echo $status_filtro == 'recebido' ? 'selected' : ''; ?>>Recebidas</option>
                        <option value="parcial" <?php echo $status_filtro == 'parcial' ? 'selected' : ''; ?>>Parciais</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-tag me-1"></i> Tipo</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="emprestimo" <?php echo $tipo_filtro == 'emprestimo' ? 'selected' : ''; ?>>Empréstimo</option>
                        <option value="beneficio" <?php echo $tipo_filtro == 'beneficio' ? 'selected' : ''; ?>>Benefício</option>
                        <option value="taxa" <?php echo $tipo_filtro == 'taxa' ? 'selected' : ''; ?>>Taxa</option>
                        <option value="mensalidade" <?php echo $tipo_filtro == 'mensalidade' ? 'selected' : ''; ?>>Mensalidade</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-search me-1"></i> Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Descrição, referência ou devedor..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100" style="background: var(--primary-gradient); border: none; border-radius: 50px; padding: 10px;">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Dívidas a Receber -->
        <?php if (empty($dividas)): ?>
            <div class="alert alert-info text-center fade-in-up">
                <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                <p>Nenhum crédito a receber encontrado.</p>
            </div>
        <?php else: ?>
            <div class="dividas-grid">
                <?php foreach ($dividas as $divida): 
                    $status_divida = $divida['status'];
                    $valor_restante = $divida['valor_restante'];
                    $percentual_recebido = $divida['valor_original'] > 0 ? round(($divida['valor_recebido'] / $divida['valor_original']) * 100, 1) : 0;
                ?>
                <div class="divida-card <?php echo $status_divida; ?> fade-in-up">
                    <div class="divida-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex gap-2 flex-wrap">
                                <?php echo getTipoBadge($divida['tipo']); ?>
                                <?php echo getStatusBadge($status_divida); ?>
                                <?php if ($divida['gerado_automaticamente'] == 1): ?>
                                    <span class="badge-auto"><i class="fas fa-robot"></i> Automático</span>
                                <?php endif; ?>
                            </div>
                            <div><small class="text-muted"><i class="fas fa-hashtag"></i> Ref: <?php echo htmlspecialchars($divida['referencia'] ?? '-'); ?></small></div>
                        </div>
                    </div>
                    <div class="divida-body">
                        <h6 class="mb-3"><?php echo htmlspecialchars($divida['descricao']); ?></h6>
                        
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-user me-1"></i> Devedor:</span>
                            <span class="info-value"><?php echo htmlspecialchars($divida['devedor_nome'] ?? '-'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-coins me-1"></i> Valor Original:</span>
                            <span class="info-value">KZ <?php echo formatarMoeda($divida['valor_original']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-check-circle text-success me-1"></i> Valor Recebido:</span>
                            <span class="info-value text-success">KZ <?php echo formatarMoeda($divida['valor_recebido']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-clock me-1"></i> Valor a Receber:</span>
                            <span class="info-value text-danger">KZ <?php echo formatarMoeda($valor_restante); ?></span>
                        </div>
                        
                        <div class="progress-recebimento">
                            <div class="progress-bar" style="width: <?php echo $percentual_recebido; ?>%; background: linear-gradient(90deg, #28a745, #20c997);"></div>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-calendar-alt me-1"></i> Vencimento:</span>
                            <span class="info-value"><?php echo formatarData($divida['data_vencimento']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-info-circle me-1"></i> Situação:</span>
                            <span class="info-value"><?php echo getSituacaoVencimento($divida['data_vencimento']); ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3 gap-2">
                            <?php if ($status_divida != 'recebido'): ?>
                                <button class="btn-receber" onclick="abrirModalRecebimento(<?php echo $divida['id']; ?>, '<?php echo addslashes($divida['descricao']); ?>', <?php echo $valor_restante; ?>, '<?php echo addslashes($divida['devedor_nome']); ?>')">
                                    <i class="fas fa-money-bill-wave"></i> Receber
                                </button>
                            <?php endif; ?>
                            <button class="btn-detalhes" onclick="verDetalhes(<?php echo $divida['id']; ?>)">
                                <i class="fas fa-eye"></i> Detalhes
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Como Funciona o Sistema de Dívidas a Receber?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-hand-holding-heart fa-4x text-primary mb-3" style="color: #006B3E !important;"></i>
                        <h4>Sistema Automático de Gestão de Créditos</h4>
                        <p class="text-muted">Entenda como funciona o processamento automático</p>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">1</div>
                        <div class="help-content">
                            <h6><i class="fas fa-user-plus text-primary"></i> Cadastro de Funcionário</h6>
                            <p>Ao cadastrar um funcionário, o sistema automaticamente gera todas as dívidas/benefícios configurados para o cargo dele.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">2</div>
                        <div class="help-content">
                            <h6><i class="fas fa-calendar-alt text-primary"></i> Novo Ano Letivo</h6>
                            <p>Quando um novo ano letivo é aberto, o sistema processa automaticamente a geração de todas as dívidas para os funcionários ativos.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">3</div>
                        <div class="help-content">
                            <h6><i class="fas fa-calculator text-primary"></i> Configurações por Cargo</h6>
                            <p>Cada cargo pode ter configurações específicas de benefícios/dívidas (valor fixo ou percentual do salário).</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">4</div>
                        <div class="help-content">
                            <h6><i class="fas fa-chart-line text-primary"></i> Status Automático</h6>
                            <p>Os status são atualizados automaticamente: "Pendente" → "Vencido" após a data, "Parcial" após recebimento, "Recebido" quando completo.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">5</div>
                        <div class="help-content">
                            <h6><i class="fas fa-hand-holding-heart text-primary"></i> Registro de Recebimento</h6>
                            <p>Ao registrar um recebimento, o sistema atualiza automaticamente o valor recebido e o status da dívida em tempo real.</p>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb"></i> <strong>Dica Importante:</strong>
                        <ul class="mb-0 mt-2">
                            <li>✅ Tudo é processado automaticamente - sem necessidade de ações manuais repetitivas</li>
                            <li>✅ Os status são atualizados em tempo real</li>
                            <li>✅ O sistema mantém histórico completo de todos os recebimentos</li>
                            <li>⚠️ Recebimentos manuais substituem os automáticos para evitar duplicidade</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Fechar</button>
                    <button type="button" class="btn btn-primary" style="background: var(--primary-gradient); border: none;" onclick="window.print()"><i class="fas fa-print"></i> Imprimir Ajuda</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Recebimento -->
    <div class="modal fade" id="modalRecebimento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i> Registrar Recebimento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="divida_id" id="divida_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Crédito:</strong> <span id="divida_descricao"></span><br>
                            <strong>Devedor:</strong> <span id="devedor_nome"></span>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle"></i> <strong>Atenção:</strong> O sistema atualizará automaticamente o status e o valor restante.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Valor a Receber</label>
                            <input type="number" step="0.01" name="valor_recebido" id="valor_recebido" class="form-control" required>
                            <small class="text-muted">Valor restante: KZ <span id="valor_restante"></span></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Data do Recebimento</label>
                            <input type="date" name="data_recebimento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Forma de Recebimento</label>
                            <select name="forma_recebimento" class="form-select" required>
                                <option value="">Selecione...</option>
                                <option value="transferencia">🏦 Transferência Bancária</option>
                                <option value="deposito">💰 Depósito</option>
                                <option value="dinheiro">💵 Dinheiro</option>
                                <option value="cheque">📝 Cheque</option>
                                <option value="compensacao">🔄 Compensação</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Observação</label>
                            <textarea name="observacao" class="form-control" rows="2" placeholder="Informações adicionais sobre o recebimento..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="registrar_recebimento" class="btn btn-success">Confirmar Recebimento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Detalhes -->
    <div class="modal fade" id="modalDetalhes" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i> Detalhes do Crédito</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesBody">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                        <p class="mt-2">Carregando...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function abrirModalRecebimento(id, descricao, valorRestante, devedor) {
            document.getElementById('divida_id').value = id;
            document.getElementById('divida_descricao').innerText = descricao;
            document.getElementById('devedor_nome').innerText = devedor;
            document.getElementById('valor_restante').innerText = valorRestante.toFixed(2).replace('.', ',');
            document.getElementById('valor_recebido').value = valorRestante;
            document.getElementById('valor_recebido').max = valorRestante;
            new bootstrap.Modal(document.getElementById('modalRecebimento')).show();
        }
        
        function verDetalhes(id) {
            const modalBody = document.getElementById('detalhesBody');
            modalBody.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i><p class="mt-2">Carregando...</p></div>';
            const modal = new bootstrap.Modal(document.getElementById('modalDetalhes'));
            modal.show();
            
            fetch(`ajax_divida_receber_detalhes.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = `
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <tr><th width="40%">Descrição:</th><td>${data.descricao || '-'}</td></tr>
                                    <tr><th>Referência:</th><td>${data.referencia || '-'}</td></tr>
                                    <tr><th>Tipo:</th><td>${data.tipo || '-'}</td></tr>
                                    <tr><th>Devedor:</th><td>${data.devedor_nome || '-'}</td></tr>
                                    <tr><th>Valor Original:</th><td class="fw-bold">KZ ${formatarMoeda(data.valor_original)}</td></tr>
                                    <tr><th>Valor Recebido:</th><td class="text-success fw-bold">KZ ${formatarMoeda(data.valor_recebido)}</td></tr>
                                    <tr><th>Valor Restante:</th><td class="text-danger fw-bold">KZ ${formatarMoeda(data.valor_restante)}</td></tr>
                                    <tr><th>Data Vencimento:</th><td>${formatarData(data.data_vencimento)}</td></tr>
                                    <tr><th>Status:</th><td>${getStatusBadgeText(data.status)}</td></tr>
                                    <tr><th>Data Criação:</th><td>${formatarData(data.created_at)}</td></tr>
                                </table>
                            </div>
                        `;
                        modalBody.innerHTML = html;
                    } else {
                        modalBody.innerHTML = '<div class="alert alert-danger">Erro ao carregar os detalhes do crédito.</div>';
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = '<div class="alert alert-danger">Erro de conexão ao carregar os detalhes.</div>';
                });
        }
        
        function formatarMoeda(valor) {
            return parseFloat(valor).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        function formatarData(data) {
            if (!data) return '-';
            return new Date(data).toLocaleDateString('pt-BR');
        }
        
        function getStatusBadgeText(status) {
            const badges = {
                recebido: '<span class="badge bg-success">Recebido</span>',
                pendente: '<span class="badge bg-warning text-dark">Pendente</span>',
                vencido: '<span class="badge bg-danger">Vencido</span>',
                parcial: '<span class="badge bg-primary">Parcial</span>'
            };
            return badges[status] || '<span class="badge bg-secondary">' + status + '</span>';
        }
        
        // Animações ao scroll
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
            
            document.querySelectorAll('.divida-card, .stat-card, .alert-auto, .filter-card').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'all 0.6s ease-out';
                observer.observe(el);
            });
        });
    </script>
</body>
</html>