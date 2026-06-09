<?php
// escola/aluno/financeiro/exportar_mensalidades_pdf.php - Relatório de Mensalidades em PDF

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
// BUSCAR MENSALIDADES NA TABELA mensalidades
// ==============================================
$sql_mensalidades = "SELECT m.*, 
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
           ELSE 'Mês inválido'
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

// Estatísticas
$total_mensalidades = count($mensalidades);
$total_pago = array_sum(array_column($mensalidades, 'valor_pago'));
$total_devedor = array_sum(array_column($mensalidades, 'valor_total')) - $total_pago;
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

// Gerar código de autenticação
$codigo_autenticacao = strtoupper(substr(md5($aluno_id . date('Ymd')), 0, 16));

// Configurar cabeçalhos
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="relatorio_mensalidades_' . $aluno_matricula . '_' . date('Ymd') . '.pdf"');
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Mensalidades - <?php echo $aluno_nome; ?></title>

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
    
    /* Configuração para formato A4 */
    body {
        font-family: 'Arial', 'Helvetica', sans-serif;
        background: white;
        padding: 0;
        margin: 0;
        width: 210mm; /* Largura A4 */
        min-height: 297mm; /* Altura A4 */
    }
    
    .relatorio-container {
        max-width: 190mm; /* Margem interna de 10mm cada lado */
        margin: 0 auto;
        background: white;
        padding: 5mm 0;
    }
    
    /* Cabeçalho */
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
        font-family: 'Arial', 'Helvetica', sans-serif;
    }
    
    .header .subtitle {
        font-size: 9pt;
        color: #666;
        font-family: 'Arial', 'Helvetica', sans-serif;
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
        font-family: 'Arial', 'Helvetica', sans-serif;
    }
    
    /* Seções de Informação */
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
        font-family: 'Arial', 'Helvetica', sans-serif;
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
        font-family: 'Arial', 'Helvetica', sans-serif;
        font-size: 10pt;
    }
    
    .info-value {
        flex: 1;
        color: #333;
        font-family: 'Arial', 'Helvetica', sans-serif;
        font-size: 10pt;
    }
    
    /* Cards de Estatísticas */
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
        font-family: 'Arial', 'Helvetica', sans-serif;
    }
    
    .stat-label {
        font-size: 8pt;
        color: #666;
        font-family: 'Arial', 'Helvetica', sans-serif;
    }
    
    /* Tabela */
    .tabela-mensalidades {
        width: 100%;
        border-collapse: collapse;
        margin: 5mm 0;
        font-size: 9pt;
    }
    
    .tabela-mensalidades th, 
    .tabela-mensalidades td {
        border: 1px solid #ddd;
        padding: 3mm 2mm;
        text-align: center;
        font-family: 'Arial', 'Helvetica', sans-serif;
    }
    
    .tabela-mensalidades th {
        background: #f5f5f5;
        font-weight: bold;
        font-size: 9pt;
    }
    
    .tabela-mensalidades td {
        font-size: 9pt;
    }
    
    /* Status */
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
    
    /* Resumo Financeiro */
    .resumo-financeiro {
        background: #f8f9fa;
        padding: 5mm;
        border-radius: 3mm;
        margin-top: 8mm;
    }
    
    /* Assinaturas */
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
    
    .assinatura div {
        font-family: 'Arial', 'Helvetica', sans-serif;
        font-size: 9pt;
        margin-top: 2mm;
    }
    
    /* Rodapé */
    .footer {
        margin-top: 10mm;
        text-align: center;
        font-size: 8pt;
        color: #999;
        border-top: 0.2mm solid #ddd;
        padding-top: 5mm;
        font-family: 'Arial', 'Helvetica', sans-serif;
    }
    
    /* Código de Autenticação */
    .codigo-autenticacao {
        font-family: 'Courier New', monospace;
        font-size: 10pt;
        background: #f0f0f0;
        padding: 3mm 5mm;
        border-radius: 3mm;
        text-align: center;
        display: inline-block;
    }
    
    /* Legenda */
    .legenda {
        font-size: 8pt;
        margin-top: 5mm;
        padding: 4mm;
        background: #f8f9fa;
        border-radius: 3mm;
        font-family: 'Arial', 'Helvetica', sans-serif;
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
    
    /* Configurações de impressão A4 */
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
        
        /* Evitar quebras de página no meio de elementos importantes */
        .info-section, 
        .stats-grid, 
        .tabela-mensalidades,
        .assinaturas,
        .footer {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        
        /* Garantir que a tabela não quebre no meio */
        .tabela-mensalidades tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
    }
    
    /* Botão de impressão (não aparece no PDF) */
    .btn-print {
        background: #006B3E;
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 5px;
        cursor: pointer;
        margin: 10px 0;
        font-size: 12px;
    }
    
    .btn-print:hover {
        background: #004d2e;
    }
    
    /* Responsivo para visualização na tela */
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
    
    /* Cores de texto auxiliares */
    .text-success {
        color: #28a745;
    }
    
    .text-danger {
        color: #dc3545;
    }
    
    .text-warning {
        color: #ffc107;
    }
    
    .text-primary {
        color: #4361ee;
    }
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
        RELATÓRIO DE MENSALIDADES
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
            <div class="stat-value"><?php echo $total_mensalidades; ?></div>
            <div class="stat-label">Total de Mensalidades</div>
        </div>
        <div class="stat-card pago">
            <div class="stat-value text-success"><?php echo formatarMoeda($total_pago); ?> Kz</div>
            <div class="stat-label">Total Pago</div>
        </div>
        <div class="stat-card devedor">
            <div class="stat-value text-danger"><?php echo formatarMoeda($total_devedor); ?> Kz</div>
            <div class="stat-label">Saldo Devedor</div>
        </div>
        <div class="stat-card adimplencia">
            <div class="stat-value"><?php echo $percentual_adimplencia; ?>%</div>
            <div class="stat-label">Adimplência</div>
        </div>
    </div>
    
    <!-- Tabela de Mensalidades -->
    <div class="info-section">
        <div class="info-title">DETALHAMENTO DAS MENSALIDADES</div>
        <div class="info-content">
            <table class="tabela-mensalidades">
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
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f0f0f0; font-weight: bold;">
                        <td colspan="3" class="text-end">TOTAIS:</td>
                        <td><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_total'))); ?> Kz</td>
                        <td><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_pago'))); ?> Kz</td>
                        <td></td>
                    </table>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Resumo Financeiro -->
    <div class="resumo-financeiro">
        <div class="info-row">
            <div class="info-label">Situação Financeira:</div>
            <div class="info-value">
                <?php if ($total_devedor <= 0): ?>
                    <span class="status-pago">✓ Regular - Nenhum débito pendente</span>
                <?php else: ?>
                    <span class="status-atrasado">⚠ Pendente - Saldo devedor de <?php echo formatarMoeda($total_devedor); ?> Kz</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="info-row">
            <div class="info-label">Mensalidades Pagas:</div>
            <div class="info-value"><?php echo $total_pagas; ?> de <?php echo $total_mensalidades; ?> (<?php echo round(($total_pagas / max($total_mensalidades, 1)) * 100, 1); ?>%)</div>
        </div>
        <div class="info-row">
            <div class="info-label">Mensalidades Pendentes:</div>
            <div class="info-value"><?php echo $total_pendentes; ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Mensalidades Atrasadas:</div>
            <div class="info-value status-atrasado"><?php echo $total_atrasadas; ?></div>
        </div>
    </div>
    
    <!-- Legenda -->
    <div class="legenda">
        <strong>Legenda:</strong>
        <span><span class="status-pago">●</span> PAGO - Mensalidade quitada</span>
        <span><span class="status-pendente">●</span> PENDENTE - Aguardando pagamento</span>
        <span><span class="status-atrasado">●</span> ATRASADO - Vencida e não paga</span>
    </div>
    
    <!-- Código de Autenticação -->
    <div class="codigo-qr" style="text-align: center; margin: 20px 0;">
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
    <button class="btn-print" onclick="window.print();">
        <i class="fas fa-print"></i> Imprimir
    </button>
    <button class="btn-print" onclick="baixarPDF();" style="background: #dc3545;">
        <i class="fas fa-file-pdf"></i> Baixar PDF
    </button>
    <button class="btn-print" onclick="window.close();" style="background: #6c757d;">
        <i class="fas fa-times"></i> Fechar
    </button>
</div>

<!-- Adicionar no head do documento -->
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
        filename: `relatorio_mensalidades_${new Date().toISOString().slice(0,10)}.pdf`,
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

<script>
    // Imprimir automaticamente ao carregar (opcional)
     //setTimeout(function() { window.print(); }, 500);
</script>
</body>
</html>