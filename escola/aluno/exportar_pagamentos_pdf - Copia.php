<?php
// escola/aluno/financeiro/exportar_pagamentos_pdf.php - Relatório de Pagamentos em PDF

require_once __DIR__ . '/../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die('Acesso negado.');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    $escola = ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
}

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR TODOS OS PAGAMENTOS DO ALUNO
// União das tabelas pagamentos e outros_pagamentos
// ==============================================
$sql_pagamentos = "
    SELECT 
        'pagamento' as origem,
        p.id,
        p.tipo_pagamento,
        tp.nome as tipo_pagamento_nome,
        p.valor as valor_total_geral,
        p.valor,
        p.data_pagamento,
        p.data_vencimento,
        p.status,
        p.metodo_pagamento,
        p.numero_fatura,
        p.numero_referencia,
        p.referente as descricao,
        p.created_at,
        NULL as mes_referencia,
        NULL as ano_referencia,
        NULL as turma_id,
        NULL as ano_letivo_id
    FROM pagamentos p
    LEFT JOIN tipos_pagamento tp ON tp.id = p.tipo_pagamento
    WHERE p.assinatura_id = :aluno_id 
    AND p.escola_id = :escola_id
    AND p.status IN ('pago', 'confirmado')
    
    UNION ALL
    
    SELECT 
        'outro_pagamento' as origem,
        op.id,
        op.tipo_pagamento_id,
        tp2.nome as tipo_pagamento_nome,
        op.valor_total as valor_total_geral,
        op.valor_pago,
        op.data_pagamento,
        op.data_vencimento,
        op.status,
        NULL as metodo_pagamento,
        NULL as numero_fatura,
        NULL as numero_referencia,
        NULL as descricao,
        op.created_at,
        op.mes_referencia,
        op.ano_referencia,
        op.turma_id,
        op.ano_letivo_id
    FROM outros_pagamentos op
    LEFT JOIN tipos_pagamento tp2 ON tp2.id = op.tipo_pagamento_id
    WHERE op.aluno_id = :aluno_id2
    AND op.escola_id = :escola_id2
    AND op.status IN ('pago', 'confirmado')
    
    ORDER BY data_pagamento DESC
";

$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':aluno_id2' => $aluno_id,
    ':escola_id2' => $escola_id
]);
$pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR MENSALIDADES (para manter o relatório de mensalidades específico)
// ==============================================
$sql_mensalidades = "SELECT 
                        m.*,
                        CASE 
                            WHEN m.mes_referencia = 1 THEN 'Janeiro'
                            WHEN m.mes_referencia = 2 THEN 'Fevereiro'
                            WHEN m.mes_referencia = 3 THEN 'Março'
                            WHEN m.mes_referencia = 4 THEN 'Abril'
                            WHEN m.mes_referencia = 5 THEN 'Maio'
                            WHEN m.mes_referencia = 6 THEN 'Junho'
                            WHEN m.mes_referencia = 7 THEN 'Julho'
                            WHEN m.mes_referencia = 8 THEN 'Agosto'
                            WHEN m.mes_referencia = 9 THEN 'Setembro'
                            WHEN m.mes_referencia = 10 THEN 'Outubro'
                            WHEN m.mes_referencia = 11 THEN 'Novembro'
                            WHEN m.mes_referencia = 12 THEN 'Dezembro'
                        END as mes_nome,
                        m.mes_referencia,
                        m.ano_referencia as ano,
                        CASE 
                            WHEN m.status = 'pago' THEN 'Pago'
                            WHEN m.data_vencimento < CURDATE() AND m.status != 'pago' THEN 'Atrasado'
                            ELSE 'Pendente'
                        END as status_texto
                    FROM mensalidades m
                    WHERE m.aluno_id = :aluno_id 
                    AND m.escola_id = :escola_id
                    ORDER BY m.ano_referencia ASC, m.mes_referencia ASC";

$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$mensalidades = $stmt_mensalidades->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS DOS PAGAMENTOS
// ==============================================
$total_pagamentos_registros = count($pagamentos);
$total_valor_pago = array_sum(array_column($pagamentos, 'valor_pago'));
$total_valor_geral = array_sum(array_column($pagamentos, 'valor_total'));

// Separar por tipo de pagamento
$pagamentos_por_tipo = [];
foreach ($pagamentos as $pg) {
    $tipo = $pg['tipo_pagamento'] ?: 'outro';
    if (!isset($pagamentos_por_tipo[$tipo])) {
        $pagamentos_por_tipo[$tipo] = [
            'quantidade' => 0,
            'total' => 0
        ];
    }
    $pagamentos_por_tipo[$tipo]['quantidade']++;
    $pagamentos_por_tipo[$tipo]['total'] += $pg['valor'];
}

// ==============================================
// ESTATÍSTICAS DAS MENSALIDADES
// ==============================================
$total_mensalidades = count($mensalidades);
$total_pago_mensalidades = array_sum(array_column($mensalidades, 'valor_pago'));
$total_devedor_mensalidades = array_sum(array_column($mensalidades, 'valor_total')) - $total_pago_mensalidades;
$total_pagas = count(array_filter($mensalidades, function($m) { return $m['status'] == 'pago'; }));
$total_pendentes = count(array_filter($mensalidades, function($m) { return $m['status'] == 'pendente'; }));
$total_atrasadas = count(array_filter($mensalidades, function($m) { 
    return $m['status'] == 'atrasado' || ($m['data_vencimento'] < date('Y-m-d') && $m['status'] != 'pago'); 
}));
$percentual_adimplencia = $total_mensalidades > 0 ? round(($total_pagas / $total_mensalidades) * 100, 1) : 0;

// Função para formatar moeda
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

// Função para obter tipo de pagamento formatado
function getTipoPagamentoLabel($tipo) {
    $tipos = [
        'mensalidade' => 'Mensalidade',
        'matricula' => 'Matrícula',
        'certificado' => 'Certificado',
        'material' => 'Material Escolar',
        'taxa' => 'Taxa',
        'atividade' => 'Atividade',
        'outro' => 'Outro'
    ];
    return $tipos[$tipo] ?? ucfirst($tipo);
}

// Gerar código de autenticação
$codigo_autenticacao = strtoupper(substr(md5($aluno_id . date('Ymd')), 0, 16));

// Configurar cabeçalhos
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="relatorio_pagamentos_' . $aluno_matricula . '_' . date('Ymd') . '.pdf"');
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Pagamentos - <?php echo $aluno_nome; ?></title>

    <style>
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            background: white;
            padding: 0;
            margin: 0;
            width: 210mm;
            min-height: 297mm;
        }
        
        .relatorio-container {
            max-width: 190mm;
            margin: 0 auto;
            background: white;
            padding: 5mm 0;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 8mm;
            margin-bottom: 10mm;
        }
        
        .header h1 {
            color: #006B3E;
            font-size: 18pt;
            margin-bottom: 3mm;
        }
        
        .header .subtitle {
            font-size: 9pt;
            color: #666;
            line-height: 1.4;
        }
        
        .titulo-relatorio {
            background: #006B3E;
            color: white;
            text-align: center;
            padding: 5mm;
            font-size: 14pt;
            font-weight: bold;
            margin: 8mm 0;
            border-radius: 3mm;
        }
        
        .info-section {
            margin-bottom: 8mm;
            border: 1px solid #ddd;
            border-radius: 3mm;
            overflow: hidden;
        }
        
        .info-title {
            background: #f5f5f5;
            padding: 4mm 5mm;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            font-size: 11pt;
        }
        
        .info-content {
            padding: 5mm;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 3mm;
            flex-wrap: wrap;
        }
        
        .info-label {
            width: 35mm;
            font-weight: bold;
            color: #555;
            font-size: 10pt;
        }
        
        .info-value {
            flex: 1;
            color: #333;
            font-size: 10pt;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4mm;
            margin-bottom: 8mm;
        }
        
        .stat-card {
            border: 1px solid #ddd;
            border-radius: 3mm;
            padding: 4mm;
            text-align: center;
            background: #f9f9f9;
        }
        
        .stat-card.total { border-top: 2mm solid #4361ee; }
        .stat-card.pago { border-top: 2mm solid #28a745; }
        .stat-card.devedor { border-top: 2mm solid #dc3545; }
        .stat-card.adimplencia { border-top: 2mm solid #8b5cf6; }
        
        .stat-value {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 2mm;
        }
        
        .stat-label {
            font-size: 8pt;
            color: #666;
        }
        
        .tabela-pagamentos {
            width: 100%;
            border-collapse: collapse;
            margin: 5mm 0;
            font-size: 8pt;
        }
        
        .tabela-pagamentos th, 
        .tabela-pagamentos td {
            border: 1px solid #ddd;
            padding: 3mm 2mm;
            text-align: center;
        }
        
        .tabela-pagamentos th {
            background: #f5f5f5;
            font-weight: bold;
        }
        
        .status-pago {
            color: #28a745;
            font-weight: bold;
        }
        
        .status-pendente {
            color: #ffc107;
            font-weight: bold;
        }
        
        .status-atrasado {
            color: #dc3545;
            font-weight: bold;
        }
        
        .resumo-financeiro {
            background: #f8f9fa;
            padding: 5mm;
            border-radius: 3mm;
            margin-top: 8mm;
        }
        
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin-top: 15mm;
            padding-top: 10mm;
        }
        
        .assinatura {
            text-align: center;
            width: 60mm;
        }
        
        .assinatura-linha {
            border-top: 0.3mm solid #333;
            margin-top: 12mm;
            padding-top: 2mm;
            width: 100%;
        }
        
        .footer {
            margin-top: 10mm;
            text-align: center;
            font-size: 8pt;
            color: #999;
            border-top: 0.2mm solid #ddd;
            padding-top: 5mm;
        }
        
        .codigo-autenticacao {
            font-family: 'Courier New', monospace;
            font-size: 10pt;
            background: #f0f0f0;
            padding: 3mm 5mm;
            border-radius: 3mm;
            text-align: center;
            display: inline-block;
        }
        
        .legenda {
            font-size: 8pt;
            margin-top: 5mm;
            padding: 4mm;
            background: #f8f9fa;
            border-radius: 3mm;
        }
        
        .legenda span {
            display: inline-block;
            margin-right: 8mm;
        }
        
        .text-end {
            text-align: right;
        }
        
        .fw-bold {
            font-weight: bold;
        }
        
        @media print {
            body {
                width: 210mm;
                min-height: 297mm;
                padding: 0;
                margin: 0;
            }
            .relatorio-container {
                max-width: 190mm;
                margin: 0 auto;
                padding: 0;
            }
            .no-print {
                display: none;
            }
            .info-section, .stats-grid, .tabela-pagamentos, .assinaturas, .footer {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .tabela-pagamentos tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
        
        @media screen {
            body {
                background: #e0e0e0;
                padding: 20px;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                width: 100%;
            }
            .relatorio-container {
                box-shadow: 0 5px 20px rgba(0,0,0,0.1);
                margin: 20px auto;
                padding: 10mm;
            }
        }
        
        .text-success { color: #28a745; }
        .text-danger { color: #dc3545; }
        .text-warning { color: #ffc107; }
        .text-primary { color: #4361ee; }
    </style>
</head>
<body>
<div class="relatorio-container">
    <div class="header">
        <h1><?php echo htmlspecialchars($escola['nome']); ?></h1>
        <div class="subtitle">
            <?php echo htmlspecialchars($escola['endereco']); ?><br>
            NIF: <?php echo htmlspecialchars($escola['nif']); ?> | 
            Tel: <?php echo htmlspecialchars($escola['telefone']); ?> |
            Email: <?php echo htmlspecialchars($escola['email']); ?>
        </div>
    </div>
    
    <div class="titulo-relatorio">
        RELATÓRIO DE PAGAMENTOS
    </div>
    
    <!-- Informações do Aluno -->
    <div class="info-section">
        <div class="info-title">DADOS DO ALUNO</div>
        <div class="info-content">
            <div class="info-row">
                <div class="info-label">Nome:</div>
                <div class="info-value"><?php echo strtoupper(htmlspecialchars($aluno_nome)); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Matrícula:</div>
                <div class="info-value"><?php echo $aluno_matricula; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Turma:</div>
                <div class="info-value"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Data de Emissão:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i:s'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-value"><?php echo $total_pagamentos_registros; ?></div>
            <div class="stat-label">Total de Pagamentos</div>
        </div>
        <div class="stat-card pago">
            <div class="stat-value text-success"><?php echo formatarMoeda($total_valor_pago); ?> Kz</div>
            <div class="stat-label">Total Pago</div>
        </div>
        <div class="stat-card devedor">
            <div class="stat-value text-danger"><?php echo formatarMoeda($total_devedor_mensalidades); ?> Kz</div>
            <div class="stat-label">Saldo Devedor</div>
        </div>
        <div class="stat-card adimplencia">
            <div class="stat-value"><?php echo $percentual_adimplencia; ?>%</div>
            <div class="stat-label">Adimplência</div>
        </div>
    </div>
    
    <!-- Tabela de Pagamentos -->
    <div class="info-section">
        <div class="info-title">DETALHAMENTO DOS PAGAMENTOS</div>
        <div class="info-content">
            <table class="tabela-pagamentos">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Descrição</th>
                        <th>Valor Pago</th>
                        <th>Forma de Pagamento</th>
                        <th>Fatura</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagamentos as $pg): 
                        $status_class = $pg['status'] == 'pago' || $pg['status'] == 'confirmado' ? 'status-pago' : 'status-pendente';
                        $status_texto = $pg['status'] == 'pago' || $pg['status'] == 'confirmado' ? 'PAGO' : 'PENDENTE';
                    ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                        <td><?php echo getTipoPagamentoLabel($pg['tipo_pagamento_nome']); ?></td>
                        <td class="text-start"><?php echo htmlspecialchars($pg['descricao'] ?? '-'); ?></td>
                        <td class="text-end text-success fw-bold"><?php echo formatarMoeda($pg['valor_total_geral']); ?> Kz</td>
                        <td><?php echo $pg['metodo_pagamento'] ? ucfirst($pg['metodo_pagamento']) : '-'; ?></td>
                        <td><?php echo $pg['numero_fatura'] ?? '-'; ?></td>
                        <td class="<?php echo $status_class; ?>"><?php echo $status_texto; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f0f0f0; font-weight: bold;">
                        <td colspan="3" class="text-end">TOTAIS:</td>
                        <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($pagamentos, 'valor_total_geral'))); ?> Kz</td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Tabela de Mensalidades -->
    <?php if (!empty($mensalidades)): ?>
    <div class="info-section">
        <div class="info-title">RESUMO DAS MENSALIDADES</div>
        <div class="info-content">
            <table class="tabela-pagamentos">
                <thead>
                    <tr>
                        <th>Mês/Ano</th>
                        <th>Data Vencimento</th>
                        <th>Data Pagamento</th>
                        <th>Valor</th>
                        <th>Valor Pago</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mensalidades as $mensalidade): 
                        $status_class = '';
                        $status_texto = '';
                        
                        if ($mensalidade['status'] == 'pago') {
                            $status_class = 'status-pago';
                            $status_texto = 'PAGO';
                        } elseif ($mensalidade['data_vencimento'] < date('Y-m-d') && $mensalidade['status'] != 'pago') {
                            $status_class = 'status-atrasado';
                            $status_texto = 'ATRASADO';
                        } else {
                            $status_class = 'status-pendente';
                            $status_texto = 'PENDENTE';
                        }
                    ?>
                    <tr>
                        <td><?php echo ($mensalidade['mes_nome'] ?? '-') . '/' . ($mensalidade['ano'] ?? '-'); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($mensalidade['data_vencimento'])); ?></td>
                        <td><?php echo $mensalidade['data_pagamento'] ? date('d/m/Y', strtotime($mensalidade['data_pagamento'])) : '-'; ?></td>
                        <td><?php echo formatarMoeda($mensalidade['valor_total']); ?> Kz</td>
                        <td><?php echo formatarMoeda($mensalidade['valor_pago'] ?? 0); ?> Kz</td>
                        <td class="<?php echo $status_class; ?>"><?php echo $status_texto; ?></td>
                    
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f0f0f0; font-weight: bold;">
                        <td colspan="3" class="text-end">TOTAIS:</td>
                        <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_total'))); ?> Kz</td>
                        <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_pago'))); ?> Kz</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Resumo Financeiro -->
    <div class="resumo-financeiro">
        <div class="info-row">
            <div class="info-label">Situação Financeira:</div>
            <div class="info-value">
                <?php if ($total_devedor_mensalidades <= 0): ?>
                    <span class="status-pago">✓ Regular - Nenhum débito pendente</span>
                <?php else: ?>
                    <span class="status-atrasado">⚠ Pendente - Saldo devedor de <?php echo formatarMoeda($total_devedor_mensalidades); ?> Kz</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="info-row">
            <div class="info-label">Total de Pagamentos:</div>
            <div class="info-value"><?php echo $total_pagamentos_registros; ?> pagamento(s) realizado(s)</div>
        </div>
        <div class="info-row">
            <div class="info-label">Valor Total Pago:</div>
            <div class="info-value text-success"><?php echo formatarMoeda($total_valor_pago); ?> Kz</div>
        </div>
        <div class="info-row">
            <div class="info-label">Mensalidades Pagas:</div>
            <div class="info-value"><?php echo $total_pagas; ?> de <?php echo $total_mensalidades; ?> (<?php echo round(($total_pagas / max($total_mensalidades, 1)) * 100, 1); ?>%)</div>
        </div>
    </div>
    
    <!-- Legenda -->
    <div class="legenda">
        <strong>Legenda:</strong>
        <span><span class="status-pago">●</span> PAGO - Pagamento confirmado</span>
        <span><span class="status-pendente">●</span> PENDENTE - Aguardando pagamento</span>
        <span><span class="status-atrasado">●</span> ATRASADO - Pagamento vencido</span>
    </div>
    
    <!-- Código de Autenticação -->
    <div style="text-align: center; margin: 20px 0;">
        <div class="codigo-autenticacao">
            <strong>Código de Autenticação:</strong> <?php echo $codigo_autenticacao; ?>
        </div>
        <small>Consulte a autenticidade deste documento na secretaria escolar</small>
    </div>
    
    <!-- Assinaturas -->
    <div class="assinaturas">
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div>Aluno / Responsável</div>
        </div>
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div>Secretaria Financeira</div>
        </div>
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div>Direção Pedagógica</div>
        </div>
    </div>
    
    <!-- Rodapé -->
    <div class="footer">
        <p>Documento emitido eletronicamente por SIGE Angola - Sistema de Gestão Escolar</p>
        <p>Data e hora da emissão: <?php echo date('d/m/Y H:i:s'); ?></p>
        <p>Este documento é válido em todo território nacional</p>
    </div>
</div>

<div class="no-print" style="text-align: center; margin-top: 20px;">
    <button class="btn-print" onclick="window.print();" style="background: #006B3E; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin: 0 5px;">
        <i class="fas fa-print"></i> Imprimir
    </button>
    <button class="btn-print" onclick="baixarPDF();" style="background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin: 0 5px;">
        <i class="fas fa-file-pdf"></i> Baixar PDF
    </button>
    <button class="btn-print" onclick="window.close();" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin: 0 5px;">
        <i class="fas fa-times"></i> Fechar
    </button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
function baixarPDF() {
    const element = document.querySelector('.relatorio-container');
    
    Swal.fire({
        title: 'Gerando PDF...',
        text: 'Aguarde enquanto o relatório está sendo gerado',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const opt = {
        margin: [0.5, 0.5, 0.5, 0.5],
        filename: `relatorio_pagamentos_${new Date().toISOString().slice(0,10)}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, letterRendering: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };
    
    html2pdf().set(opt).from(element).save().then(() => {
        Swal.fire({
            icon: 'success',
            title: 'PDF Gerado!',
            text: 'O download foi iniciado com sucesso',
            timer: 2000,
            showConfirmButton: false
        });
    }).catch((error) => {
        Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: 'Erro ao gerar PDF. Tente novamente.',
            confirmButtonColor: '#dc3545'
        });
    });
}
</script>

</body>
</html>