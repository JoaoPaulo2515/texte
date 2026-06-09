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
    switch ($status) {
        case 'pago':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
        case 'aprovado':
            return '<span class="badge bg-info"><i class="fas fa-thumbs-up"></i> Aprovado</span>';
        case 'processado':
            return '<span class="badge bg-primary"><i class="fas fa-calculator"></i> Processado</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'cancelado':
            return '<span class="badge bg-danger"><i class="fas fa-ban"></i> Cancelado</span>';
        default:
            return '<span class="badge bg-secondary">Indefinido</span>';
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Salário | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .salary-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .salary-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            padding: 20px;
            color: white;
        }
        .salary-amount {
            font-size: 48px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .salary-label {
            font-size: 14px;
            opacity: 0.9;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .info-title {
            font-size: 1.1em;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #006B3E;
        }
        .info-row {
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
        }
        .info-label {
            font-weight: bold;
            color: #666;
        }
        .info-value {
            font-weight: 500;
            color: #333;
        }
        .info-value.positive {
            color: #28a745;
        }
        .info-value.negative {
            color: #dc3545;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        .btn-pdf {
            background: #dc3545;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            border: none;
        }
        .btn-pdf:hover {
            background: #bd2130;
            color: white;
        }
        .btn-print {
            background: #17a2b8;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            border: none;
        }
        .btn-print:hover {
            background: #138496;
            color: white;
        }
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        .progress-bar-custom {
            height: 10px;
            border-radius: 5px;
        }
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stats-number {
            font-size: 24px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-money-bill-wave"></i> Meu Salário</h2>
                    <p>Informações sobre sua remuneração e benefícios</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn me-2"><i class="fas fa-arrow-left"></i> Voltar ao Dashboard</a>
                    <button onclick="gerarPDF()" class="btn-pdf btn me-2"><i class="fas fa-file-pdf"></i> PDF</button>
                    <button onclick="window.print()" class="btn-print btn"><i class="fas fa-print"></i> Imprimir</button>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="salary-card">
                    <div class="salary-header text-center">
                        <div class="salary-label">Salário Líquido</div>
                        <div class="salary-amount">KZ <?php echo formatarMoeda($salario['salario_liquido']); ?></div>
                        <div class="salary-label"><i class="fas fa-calendar-alt"></i> <?php echo $mes_atual . ' de ' . $ano_referencia; ?></div>
                        <div class="mt-2"><?php echo getStatusBadge($salario['status'] ?? 'pendente'); ?></div>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <small class="text-muted"><i class="fas fa-clock"></i> Processado em: <?php echo formatarData($salario['data_processamento'] ?? date('Y-m-d')); ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-success">KZ <?php echo formatarMoeda($salario['total_vencimentos']); ?></div>
                            <small>Total de Vencimentos</small>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="stats-card">
                            <div class="stats-number text-danger">KZ <?php echo formatarMoeda($salario['total_descontos']); ?></div>
                            <small>Total de Descontos</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-arrow-up text-success"></i> Vencimentos</div>
                    <div class="info-row"><span class="info-label">Salário Base</span><span class="info-value positive">KZ <?php echo formatarMoeda($salario['salario_base']); ?></span></div>
                    <div class="info-row"><span class="info-label">Subsídio de Transporte</span><span class="info-value positive">KZ <?php echo formatarMoeda($salario['subsidio_transporte']); ?></span></div>
                    <div class="info-row"><span class="info-label">Subsídio de Alimentação</span><span class="info-value positive">KZ <?php echo formatarMoeda($salario['subsidio_alimentacao']); ?></span></div>
                    <div class="info-row"><span class="info-label">Gratificação</span><span class="info-value positive">KZ <?php echo formatarMoeda($salario['gratificacao']); ?></span></div>
                    <div class="info-row"><span class="info-label">Seguro Saúde</span><span class="info-value positive">KZ <?php echo formatarMoeda($salario['seguro_saude']); ?></span></div>
                    <div class="info-row"><span class="info-label">Horas Extras</span><span class="info-value positive">KZ <?php echo formatarMoeda($salario['horas_extras_valor']); ?></span></div>
                    <div class="info-row"><span class="info-label">Outros Vencimentos</span><span class="info-value positive">KZ <?php echo formatarMoeda($salario['outros_vencimentos']); ?></span></div>
                    <div class="info-row total-row"><span class="info-label"><strong>TOTAL VENCIMENTOS</strong></span><span class="info-value positive"><strong>KZ <?php echo formatarMoeda($salario['total_vencimentos']); ?></strong></span></div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-arrow-down text-danger"></i> Descontos</div>
                    <div class="info-row"><span class="info-label">Faltas</span><span class="info-value negative">KZ <?php echo formatarMoeda($salario['faltas_valor']); ?></span></div>
                    <div class="info-row"><span class="info-label">IRPS (Imposto)</span><span class="info-value negative">KZ <?php echo formatarMoeda($salario['desconto_irps']); ?></span></div>
                    <div class="info-row"><span class="info-label">Segurança Social</span><span class="info-value negative">KZ <?php echo formatarMoeda($salario['desconto_seguranca_social']); ?></span></div>
                    <div class="info-row"><span class="info-label">Atrasos</span><span class="info-value negative">KZ <?php echo formatarMoeda($salario['desconto_atrasos']); ?></span></div>
                    <div class="info-row"><span class="info-label">Empréstimo</span><span class="info-value negative">KZ <?php echo formatarMoeda($salario['desconto_emprestimo']); ?></span></div>
                    <div class="info-row"><span class="info-label">Outros Descontos</span><span class="info-value negative">KZ <?php echo formatarMoeda($salario['outros_descontos']); ?></span></div>
                    <div class="info-row total-row"><span class="info-label"><strong>TOTAL DESCONTOS</strong></span><span class="info-value negative"><strong>KZ <?php echo formatarMoeda($salario['total_descontos']); ?></strong></span></div>
                </div>
            </div>
        </div>
        
        <div class="info-card">
            <div class="info-title"><i class="fas fa-calculator"></i> Resumo do Salário Líquido</div>
            <div class="row">
                <div class="col-md-8">
                    <div class="d-flex justify-content-between mb-2"><span>Total de Vencimentos</span><span class="text-success">KZ <?php echo formatarMoeda($salario['total_vencimentos']); ?></span></div>
                    <div class="d-flex justify-content-between mb-2"><span>Total de Descontos</span><span class="text-danger">KZ <?php echo formatarMoeda($salario['total_descontos']); ?></span></div>
                    <div class="progress-bar-custom bg-light mt-2 mb-3"><div class="progress-bar progress-bar-striped bg-success" style="width: <?php echo $salario['total_vencimentos'] > 0 ? round(($salario['total_vencimentos'] / ($salario['total_vencimentos'] + $salario['total_descontos'])) * 100, 1) : 0; ?>%"></div></div>
                    <div class="d-flex justify-content-between mt-3 pt-2 border-top"><strong>Salário Líquido</strong><strong class="text-success">KZ <?php echo formatarMoeda($salario['salario_liquido']); ?></strong></div>
                </div>
                <div class="col-md-4 text-center"><div class="alert alert-info mb-0"><i class="fas fa-info-circle"></i><p class="mb-0 small mt-1">Este é o valor líquido a receber após todos os descontos.</p></div></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-user"></i> Dados do Professor</div>
                    <div class="info-row"><span class="info-label">Nome:</span><span class="info-value"><?php echo htmlspecialchars($professor_dados['nome'] ?? ''); ?></span></div>
                    <div class="info-row"><span class="info-label">Email:</span><span class="info-value"><?php echo htmlspecialchars($professor_dados['email'] ?? ''); ?></span></div>
                    <div class="info-row"><span class="info-label">Data Admissão:</span><span class="info-value"><?php echo formatarData($professor_dados['data_admissao'] ?? ''); ?></span></div>
                    <div class="info-row"><span class="info-label">Ano Letivo:</span><span class="info-value"><?php echo $ano_letivo_ano; ?></span></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-building"></i> Instituição</div>
                    <div class="info-row"><span class="info-label">Escola:</span><span class="info-value"><?php echo htmlspecialchars($escola['nome'] ?? 'Não definida'); ?></span></div>
                    <div class="info-row"><span class="info-label">Endereço:</span><span class="info-value"><?php echo htmlspecialchars($escola['endereco'] ?? 'Não informado'); ?></span></div>
                    <div class="info-row"><span class="info-label">Telefone:</span><span class="info-value"><?php echo htmlspecialchars($escola['telefone'] ?? 'Não informado'); ?></span></div>
                    <div class="info-row"><span class="info-label">Email:</span><span class="info-value"><?php echo htmlspecialchars($escola['email'] ?? 'Não informado'); ?></span></div>
                </div>
            </div>
        </div>
        
        <div class="text-center text-muted small mt-4"><hr><i class="fas fa-file-invoice-dollar"></i> Documento emitido eletronicamente pelo SIGE Angola em <?php echo date('d/m/Y H:i:s'); ?></div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>function gerarPDF(){window.open('gerar_pdf_salario.php','_blank');}</script>
</body>
</html>