<?php
// escola/financeiro/folha_pagamento/visualizar_folha_pagamento.php - Visualizar Folha de Pagamento Geral
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

// Carregar DOMPDF via Composer
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
} else {
    die("DOMPDF não está instalado. Execute: composer require dompdf/dompdf");
}

use Dompdf\Dompdf;
use Dompdf\Options;

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$processamento_id = $_GET['processamento_id'] ?? 0;
$download = isset($_GET['download']) ? true : false;

// Buscar dados do processamento
$stmt = $conn->prepare("
    SELECT * FROM folha_processamento_cabecalho 
    WHERE id = ? AND escola_id = ?
");
$stmt->execute([$processamento_id, $escola_id]);
$processamento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$processamento) {
    die("Processamento não encontrado.");
}

// Buscar dados da escola
$stmt = $conn->prepare("SELECT * FROM escolas WHERE id = ?");
$stmt->execute([$escola_id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar funcionários no processamento
$stmt = $conn->prepare("
    SELECT 
        pf.funcionario_id,
        f.numero_processo,
        f.nome,
        f.cargo,
        f.bi,
        f.data_admissao,
        f.tipo_contrato,
        f.banco_nome,
        f.numero_conta,
        f.iban,
        pf.salario_base,
        pf.subsidio_transporte,
        pf.subsidio_alimentacao,
        pf.outros_vencimentos,
        pf.faltas_dias,
        pf.faltas_valor,
        pf.valor_inss,
        pf.valor_irrf,
        pf.total_vencimentos,
        pf.total_descontos,
        pf.salario_liquido
    FROM folha_processamento_funcionarios pf
    JOIN funcionarios f ON pf.funcionario_id = f.id
    WHERE pf.processamento_id = ?
    ORDER BY f.nome
");
$stmt->execute([$processamento_id]);
$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Meses em português
$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$mes_extenso = $meses[$processamento['mes']];
$ano = $processamento['ano'];
$data_atual = date('d/m/Y H:i:s');

// Calcular totais gerais
$total_funcionarios = count($funcionarios);
$total_salario_base = 0;
$total_subsidios = 0;
$total_faltas = 0;
$total_inss = 0;
$total_irrf = 0;
$total_liquido = 0;

foreach ($funcionarios as $f) {
    $total_salario_base += $f['salario_base'];
    $total_subsidios += ($f['subsidio_transporte'] + $f['subsidio_alimentacao'] + $f['outros_vencimentos']);
    $total_faltas += $f['faltas_valor'];
    $total_inss += $f['valor_inss'];
    $total_irrf += $f['valor_irrf'];
    $total_liquido += $f['salario_liquido'];
}

// Gerar HTML da folha de pagamento
$html = '
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Folha de Pagamento - ' . $mes_extenso . '/' . $ano . '</title>
    <style>
        @page {
            margin: 1.5cm;
            size: A4 landscape;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 10px;
        }
        
        .logo {
            font-size: 16pt;
            font-weight: bold;
            color: #006B3E;
        }
        
        .escola-nome {
            font-size: 14pt;
            font-weight: bold;
            color: #1A2A6C;
        }
        
        .titulo {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin: 15px 0;
            text-decoration: underline;
        }
        
        .info-periodo {
            text-align: center;
            margin-bottom: 15px;
            font-size: 10pt;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 8pt;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
        }
        
        th {
            background-color: #e9ecef;
            font-weight: bold;
            text-align: center;
        }
        
        .text-end {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .total-row {
            background-color: #d4edda;
            font-weight: bold;
        }
        
        .assinatura {
            margin-top: 30px;
            text-align: center;
        }
        
        .assinatura-linha {
            margin-top: 20px;
            width: 200px;
            border-top: 1px solid #000;
            margin-left: auto;
            margin-right: auto;
        }
        
        .rodape {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7pt;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 8px;
        }
        
        .data-local {
            text-align: right;
            margin-bottom: 10px;
            font-size: 8pt;
        }
        
        .resumo-card {
            background: #f8f9fa;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-size: 9pt;
        }
        
        .resumo-card span {
            font-weight: bold;
            color: #006B3E;
        }
        
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="logo">SIGE Angola</div>
    <div class="escola-nome">' . htmlspecialchars($escola['nome'] ?? 'Escola') . '</div>
    <div>' . htmlspecialchars($escola['provincia'] ?? '') . ' - ' . htmlspecialchars($escola['municipio'] ?? '') . '</div>
    <div>Telefone: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</div>
</div>

<div class="data-local">
    Luanda, ' . date('d/m/Y') . '
</div>

<div class="titulo">
    FOLHA DE PAGAMENTO
</div>

<div class="info-periodo">
    <strong>Competência:</strong> ' . $mes_extenso . ' de ' . $ano . '
</div>

<div class="resumo-card">
    <strong>RESUMO GERAL</strong><br>
    Total de Funcionários: <span>' . $total_funcionarios . '</span> |
    Massa Salarial: <span>' . number_format($total_liquido, 2) . ' Kz</span> |
    Total de Vencimentos: <span>' . number_format($total_salario_base + $total_subsidios, 2) . ' Kz</span> |
    Total de Descontos: <span>' . number_format($total_faltas + $total_inss + $total_irrf, 2) . ' Kz</span>
</div>

<table>
    <thead>
        <tr>
            <th>Nº Processo</th>
            <th>Nome</th>
            <th>Cargo</th>
            <th>BI</th>
            <th>IBAN</th>
            <th class="text-end">Salário Base</th>
            <th class="text-end">Subsídios</th>
            <th class="text-end">Faltas</th>
            <th class="text-end">INSS</th>
            <th class="text-end">IRRF</th>
            <th class="text-end">Líquido</th>
        </tr>
    </thead>
    <tbody>';

foreach ($funcionarios as $func) {
    $subsidios = ($func['subsidio_transporte'] + $func['subsidio_alimentacao'] + $func['outros_vencimentos']);
    $html .= '
        <tr>
            <td class="text-center">' . htmlspecialchars($func['numero_processo']) . '</td>
            <td>' . htmlspecialchars($func['nome']) . '</td>
            <td>' . htmlspecialchars($func['cargo']) . '</td>
            <td class="text-center">' . htmlspecialchars($func['bi']) . '</td>
            <td class="text-center">' . htmlspecialchars($func['iban'] ?? '-') . '</td>
            <td class="text-end">' . number_format($func['salario_base'], 2) . '</td>
            <td class="text-end">' . number_format($subsidios, 2) . '</td>
            <td class="text-end text-danger">' . number_format($func['faltas_valor'], 2) . '</td>
            <td class="text-end">' . number_format($func['valor_inss'], 2) . '</td>
            <td class="text-end">' . number_format($func['valor_irrf'], 2) . '</td>
            <td class="text-end"><strong>' . number_format($func['salario_liquido'], 2) . '</strong></td>
        </tr>';
}

$html .= '
    </tbody>
    <tfoot>
        <tr class="total-row">
            <th colspan="5" class="text-end">TOTAIS:</th>
            <th class="text-end">' . number_format($total_salario_base, 2) . '</th>
            <th class="text-end">' . number_format($total_subsidios, 2) . '</th>
            <th class="text-end">' . number_format($total_faltas, 2) . '</th>
            <th class="text-end">' . number_format($total_inss, 2) . '</th>
            <th class="text-end">' . number_format($total_irrf, 2) . '</th>
            <th class="text-end"><strong>' . number_format($total_liquido, 2) . '</strong></th>
        </tr>
    </tfoot>
</table>

<div class="assinatura">
    <div class="assinatura-linha"></div>
    <div>Assinatura e Carimbo da Empresa</div>
</div>

<div class="rodape">
    Sistema Integrado de Gestão Escolar - SIGE Angola | Documento gerado eletronicamente em ' . $data_atual . '<br>
    Este documento é válido apenas com assinatura e carimbo da instituição.
</div>

</body>
</html>';

// Configurar DOMPDF
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Nome do arquivo
$nome_arquivo = 'folha_pagamento_' . $ano . '_' . str_pad($processamento['mes'], 2, '0', STR_PAD_LEFT) . '.pdf';

// Se for download
if ($download) {
    $dompdf->stream($nome_arquivo, array('Attachment' => true));
    exit;
}

// Criar diretório se não existir
$upload_dir = __DIR__ . '/../../../uploads/folha_pagamento/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$caminho_completo = $upload_dir . $nome_arquivo;
file_put_contents($caminho_completo, $dompdf->output());
$pdf_url = '../../../uploads/folha_pagamento/' . $nome_arquivo;

// Buscar processamento_id da URL
$processamento_id_url = $_GET['processamento_id'] ?? 0;
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Folha de Pagamento | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .pdf-container { background: #525659; padding: 20px; border-radius: 10px; text-align: center; }
        .pdf-viewer { width: 100%; height: 80vh; border: none; border-radius: 8px; background: white; }
        .info-card { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 15px; }
        .info-label { font-weight: bold; width: 140px; display: inline-block; color: #006B3E; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
            <li class="nav-item"><a href="funcionarios.php" class="nav-link"><i class="fas fa-users"></i> Funcionários</a></li>
            <li class="nav-item"><a href="processar.php" class="nav-link"><i class="fas fa-calculator"></i> Processar</a></li>
            <li class="nav-item"><a href="holerites.php" class="nav-link"><i class="fas fa-receipt"></i> Holerites</a></li>
            <li class="nav-item"><a href="relatorios.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="configuracoes.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-file-pdf"></i> Folha de Pagamento Geral</h2>
            <div>
                <a href="processar.php?ano=<?php echo $ano; ?>&mes=<?php echo $processamento['mes']; ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
                <a href="visualizar_folha_pagamento.php?processamento_id=<?php echo $processamento_id_url; ?>&download=1" class="btn btn-primary btn-sm ms-2">
                    <i class="fas fa-download"></i> Baixar PDF
                </a>
                <button class="btn btn-info btn-sm ms-2" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Informações da Folha -->
        <div class="row">
            <div class="col-md-3">
                <div class="info-card text-center">
                    <i class="fas fa-calendar fa-2x text-primary"></i>
                    <h4><?php echo $mes_extenso . '/' . $ano; ?></h4>
                    <small>Período</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-card text-center">
                    <i class="fas fa-users fa-2x text-success"></i>
                    <h4><?php echo $total_funcionarios; ?></h4>
                    <small>Total de Funcionários</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-card text-center">
                    <i class="fas fa-money-bill fa-2x text-warning"></i>
                    <h4><?php echo number_format($total_liquido, 2); ?> Kz</h4>
                    <small>Massa Salarial</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-card text-center">
                    <i class="fas fa-chart-line fa-2x text-info"></i>
                    <h4><?php echo number_format($total_salario_base + $total_subsidios, 2); ?> Kz</h4>
                    <small>Total Vencimentos</small>
                </div>
            </div>
        </div>
        
        <!-- Visualização do PDF -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-file-pdf"></i> Documento - Folha de Pagamento
            </div>
            <div class="card-body pdf-container">
                <iframe src="<?php echo $pdf_url; ?>" class="pdf-viewer" frameborder="0">
                    Este navegador não suporta visualização de PDF. 
                    <a href="<?php echo $pdf_url; ?>">Clique aqui para baixar o arquivo</a>
                </iframe>
            </div>
        </div>
        
        <div class="alert alert-info mt-3">
            <i class="fas fa-info-circle"></i> 
            <strong>Dica:</strong> Este documento contém a folha de pagamento completa com todos os funcionários. 
            Utilize os botões acima para baixar ou imprimir.
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
    </script>
</body>
</html>