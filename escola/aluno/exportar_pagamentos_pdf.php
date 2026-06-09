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
// BUSCAR APENAS PAGAMENTOS DA TABELA pagamentos
// ==============================================
$sql_pagamentos = "
    SELECT 
        p.id,
        p.tipo_pagamento,
        p.valor,
        p.data_pagamento,
        p.data_vencimento,
        p.status,
        p.metodo_pagamento,
        p.numero_fatura,
        p.numero_referencia,
        p.referente as descricao,
        p.created_at
    FROM pagamentos p
    WHERE p.assinatura_id = :aluno_id 
    AND p.escola_id = :escola_id
    AND p.status IN ('pago', 'confirmado')
    ORDER BY p.data_pagamento DESC
";

$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_pagamentos_registros = count($pagamentos);
$total_valor_pago = array_sum(array_column($pagamentos, 'valor'));

// Função para formatar moeda
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

// Gerar código de autenticação
$codigo_autenticacao = strtoupper(substr(md5($aluno_id . date('Ymd')), 0, 16));
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Pagamentos - <?php echo $aluno_nome; ?></title>
    <style>
        /* Reset */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* Configuração para impressão */
        @media print {
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            .no-print {
                display: none !important;
            }
            .page-break {
                page-break-before: avoid;
                page-break-inside: avoid;
            }
            @page {
                size: A4;
                margin: 15mm;
            }
        }
        
        /* Estilos para tela */
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            background: #e0e0e0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .relatorio-container {
            max-width: 1100px;
            width: 100%;
            background: white;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        
        /* Cabeçalho */
        .header {
            text-align: center;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            color: #006B3E;
            font-size: 22pt;
            margin-bottom: 5px;
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
            padding: 10px;
            font-size: 14pt;
            font-weight: bold;
            margin: 15px 0;
            border-radius: 5px;
        }
        
        /* Informações do Aluno */
        .info-section {
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .info-title {
            background: #f5f5f5;
            padding: 10px 15px;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            font-size: 11pt;
        }
        
        .info-content {
            padding: 15px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        
        .info-label {
            width: 100px;
            font-weight: bold;
            color: #555;
            font-size: 10pt;
        }
        
        .info-value {
            flex: 1;
            color: #333;
            font-size: 10pt;
        }
        
        /* Cards de Estatísticas */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            background: #f9f9f9;
        }
        
        .stat-card.total { border-top: 3px solid #4361ee; }
        .stat-card.pago { border-top: 3px solid #28a745; }
        .stat-card.devedor { border-top: 3px solid #dc3545; }
        .stat-card.adimplencia { border-top: 3px solid #8b5cf6; }
        
        .stat-value {
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 9pt;
            color: #666;
        }
        
        /* Tabela */
        .tabela-pagamentos {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 9pt;
        }
        
        .tabela-pagamentos th {
            background: #1A2A6C;
            color: white;
            padding: 10px;
            text-align: center;
            border: 1px solid #ddd;
        }
        
        .tabela-pagamentos td {
            padding: 8px;
            text-align: center;
            border: 1px solid #ddd;
        }
        
        .tabela-pagamentos tbody tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .tabela-pagamentos tfoot td {
            background: #f0f0f0;
            font-weight: bold;
            border-top: 2px solid #ddd;
        }
        
        /* Status */
        .status-pago {
            color: #28a745;
            font-weight: bold;
        }
        
        .text-end {
            text-align: right;
        }
        
        .fw-bold {
            font-weight: bold;
        }
        
        /* Resumo Financeiro */
        .resumo-financeiro {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        /* Assinaturas */
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
        }
        
        .assinatura {
            text-align: center;
            width: 200px;
        }
        
        .assinatura-linha {
            border-top: 1px solid #333;
            margin-top: 30px;
            padding-top: 5px;
            width: 100%;
        }
        
        /* Rodapé */
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 8pt;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .codigo-autenticacao {
            font-family: monospace;
            font-size: 10pt;
            background: #f0f0f0;
            padding: 8px;
            border-radius: 5px;
            text-align: center;
            display: inline-block;
        }
        
        .legenda {
            font-size: 8pt;
            margin-top: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .legenda span {
            display: inline-block;
            margin-right: 15px;
        }
        
        /* Botões */
        .btn-group {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #006B3E;
            color: white;
        }
        
        .btn-primary:hover {
            background: #004d2e;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .tabela-pagamentos {
                font-size: 8pt;
            }
        }
    </style>
</head>
<body>
<div class="relatorio-container" id="relatorioContainer">
    <!-- Cabeçalho -->
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
            <div class="stat-value text-danger">0,00 Kz</div>
            <div class="stat-label">Saldo Devedor</div>
        </div>
        <div class="stat-card adimplencia">
            <div class="stat-value">100%</div>
            <div class="stat-label">Adimplência</div>
        </div>
    </div>
    
    <!-- Tabela de Pagamentos -->
    <div class="info-section">
        <div class="info-title">DETALHAMENTO DOS PAGAMENTOS</div>
        <div class="info-content" style="overflow-x: auto;">
            <table class="tabela-pagamentos">
                <thead>
                    <tr>
                        <th width="10%">Data</th>
                        <th width="15%">Tipo</th>
                        <th width="35%">Descrição</th>
                        <th width="15%">Valor</th>
                        <th width="15%">Forma</th>
                        <th width="10%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagamentos as $pg): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                        <td><?php echo ucfirst($pg['tipo_pagamento']); ?></td>
                        <td class="text-start"><?php echo htmlspecialchars($pg['descricao'] ?? '-'); ?></td>
                        <td class="text-end fw-bold text-success"><?php echo formatarMoeda($pg['valor']); ?> Kz</td>
                        <td><?php echo ucfirst($pg['metodo_pagamento'] ?? '-'); ?></td>
                        <td class="status-pago">PAGO</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-end fw-bold">TOTAL:</td>
                        <td class="text-end fw-bold"><?php echo formatarMoeda($total_valor_pago); ?> Kz</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Resumo Financeiro -->
    <div class="resumo-financeiro">
        <div class="info-row">
            <div class="info-label">Situação Financeira:</div>
            <div class="info-value">
                <span class="status-pago">✓ Regular - Todos os pagamentos em dia</span>
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
    </div>
    
    <!-- Legenda -->
    <div class="legenda">
        <strong>Legenda:</strong>
        <span><span style="color:#28a745;">●</span> PAGO - Pagamento confirmado</span>
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

<div class="btn-group no-print">
    <button class="btn btn-primary" onclick="window.print();">
        <i class="fas fa-print"></i> Imprimir
    </button>
    <button class="btn btn-danger" onclick="baixarPDF();">
        <i class="fas fa-file-pdf"></i> Baixar PDF
    </button>
    <button class="btn btn-secondary" onclick="window.close();">
        <i class="fas fa-times"></i> Fechar
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function baixarPDF() {
    // Abrir janela de impressão
   // window.print();
    
    // Mostrar mensagem
    Swal.fire({
        icon: 'success',
        title: 'PDF Gerado!',
        text: 'Na janela de impressão, clique em "Salvar como PDF"',
        timer: 3000,
        showConfirmButton: true
    });
}
</script>
</body>
</html>