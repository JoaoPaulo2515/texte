<?php
// escola/financeiro/folha_pagamento/gerar_holerites_lote.php - Gerar Holerites em Lote
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

// Buscar funcionários do processamento com todos os dados
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
        pf.total_vencimentos,
        pf.faltas_dias,
        pf.faltas_valor,
        pf.valor_inss,
        pf.valor_irrf,
        pf.total_descontos,
        pf.salario_liquido
    FROM folha_processamento_funcionarios pf
    JOIN funcionarios f ON pf.funcionario_id = f.id
    WHERE pf.processamento_id = ?
    GROUP BY pf.funcionario_id
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

// Função para converter número em extenso
function valorExtenso($valor) {
    $valor = round($valor, 2);
    $parte_inteira = floor($valor);
    $parte_decimal = round(($valor - $parte_inteira) * 100);
    
    $extenso = numeroPorExtenso($parte_inteira);
    
    if ($parte_decimal > 0) {
        $extenso .= ' e ' . numeroPorExtenso($parte_decimal) . ' cêntimos';
    } else {
        $extenso .= ' kwanzas';
    }
    
    return ucfirst($extenso);
}

function numeroPorExtenso($numero) {
    $unidades = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];
    $especiais = [10 => 'dez', 11 => 'onze', 12 => 'doze', 13 => 'treze', 14 => 'catorze', 
                  15 => 'quinze', 16 => 'dezasseis', 17 => 'dezassete', 18 => 'dezoito', 19 => 'dezanove'];
    $dezenas = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $centenas = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    
    if ($numero == 0) return 'zero';
    if ($numero < 0) return 'menos ' . numeroPorExtenso(-$numero);
    
    $extenso = '';
    
    if ($numero >= 1000) {
        $milhares = floor($numero / 1000);
        if ($milhares == 1) {
            $extenso .= 'mil';
        } else {
            $extenso .= numeroPorExtenso($milhares) . ' mil';
        }
        $numero %= 1000;
        if ($numero > 0) $extenso .= ' e ';
    }
    
    if ($numero >= 100) {
        $centena = floor($numero / 100);
        if ($centena == 1 && $numero % 100 == 0) {
            $extenso .= 'cem';
        } else {
            $extenso .= $centenas[$centena];
        }
        $numero %= 100;
        if ($numero > 0) $extenso .= ' e ';
    }
    
    if ($numero >= 10 && $numero <= 19) {
        $extenso .= $especiais[$numero];
    } elseif ($numero >= 20) {
        $dezena = floor($numero / 10);
        $unidade = $numero % 10;
        $extenso .= $dezenas[$dezena];
        if ($unidade > 0) {
            $extenso .= ' e ' . $unidades[$unidade];
        }
    } elseif ($numero > 0) {
        $extenso .= $unidades[$numero];
    }
    
    return $extenso;
}

// Criar diretório para os holerites
$upload_dir = __DIR__ . '/../../../uploads/holerites/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Criar tabela folha_holerites se não existir
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS folha_holerites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            escola_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            processamento_id INT NOT NULL,
            ano INT NOT NULL,
            mes INT NOT NULL,
            salario_base DECIMAL(10,2) DEFAULT 0,
            total_vencimentos DECIMAL(10,2) DEFAULT 0,
            total_descontos DECIMAL(10,2) DEFAULT 0,
            salario_liquido DECIMAL(10,2) DEFAULT 0,
            caminho_pdf VARCHAR(500),
            codigo_verificacao VARCHAR(100),
            data_emissao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // Tabela já existe
}

$holerites_gerados = [];
$erros = [];
$total_massa_salarial = 0;

// Gerar holerite para cada funcionário
foreach ($funcionarios as $funcionario) {
    // Pegar os valores diretamente do array do funcionário
    $salario_base = $funcionario['salario_base'] ?? 0;
    $subsidio_transporte = $funcionario['subsidio_transporte'] ?? 0;
    $subsidio_alimentacao = $funcionario['subsidio_alimentacao'] ?? 0;
    $outros_vencimentos = $funcionario['outros_vencimentos'] ?? 0;
    $faltas_valor = $funcionario['faltas_valor'] ?? 0;
    $faltas_dias = $funcionario['faltas_dias'] ?? 0;
    $inss = $funcionario['valor_inss'] ?? 0;
    $irrf = $funcionario['valor_irrf'] ?? 0;
    
    $total_vencimentos = $salario_base + $subsidio_transporte + $subsidio_alimentacao + $outros_vencimentos;
    $total_descontos = $inss + $irrf + $faltas_valor;
    $salario_liquido = $total_vencimentos - $total_descontos;
    
    $total_massa_salarial += $salario_liquido;
    
    // Gerar HTML do holerite
    $html = '
    <!DOCTYPE html>
    <html lang="pt-AO">
    <head>
        <meta charset="UTF-8">
        <title>Holerite - ' . htmlspecialchars($funcionario['nome']) . '</title>
        <style>
            @page { margin: 1.5cm; size: A4; }
            body { font-family: "DejaVu Sans", "Arial", sans-serif; font-size: 10pt; line-height: 1.4; color: #333; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #006B3E; padding-bottom: 10px; }
            .logo { font-size: 18pt; font-weight: bold; color: #006B3E; }
            .escola-nome { font-size: 14pt; font-weight: bold; color: #1A2A6C; }
            .titulo { text-align: center; font-size: 14pt; font-weight: bold; margin: 15px 0; text-decoration: underline; }
            .info-funcionario { background: #f5f5f5; padding: 10px; margin-bottom: 15px; border-radius: 5px; }
            .info-label { font-weight: bold; width: 120px; display: inline-block; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #e9ecef; font-weight: bold; }
            .text-end { text-align: right; }
            .total-row { background-color: #d4edda; font-weight: bold; }
            .assinatura { margin-top: 40px; text-align: center; }
            .assinatura-linha { margin-top: 30px; width: 250px; border-top: 1px solid #000; margin-left: auto; margin-right: auto; }
            .rodape { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 8pt; color: #999; border-top: 1px solid #ddd; padding-top: 8px; }
            .data-local { text-align: right; margin-bottom: 10px; font-size: 9pt; }
            .codigo-verificacao { font-size: 8pt; text-align: center; margin-top: 15px; color: #666; }
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
        HOLERITE DE PAGAMENTO
    </div>
    
    <div class="info-funcionario">
        <strong>DADOS DO FUNCIONÁRIO</strong><br>
        <span class="info-label">Nº Processo:</span> ' . htmlspecialchars($funcionario['numero_processo']) . '<br>
        <span class="info-label">Nome:</span> ' . htmlspecialchars($funcionario['nome']) . '<br>
        <span class="info-label">Cargo:</span> ' . htmlspecialchars($funcionario['cargo']) . '<br>
        <span class="info-label">BI:</span> ' . htmlspecialchars($funcionario['bi']) . '<br>
        <span class="info-label">Data Admissão:</span> ' . date('d/m/Y', strtotime($funcionario['data_admissao'])) . '<br>
        <span class="info-label">Tipo Contrato:</span> ' . htmlspecialchars($funcionario['tipo_contrato']) . '<br>
        <span class="info-label">Competência:</span> ' . $mes_extenso . '/' . $ano . '
    </div>
    
    <table>
        <thead>
            <tr><th colspan="2">VENCIMENTOS</th><th class="text-end">VALOR (Kz)</th></tr>
        </thead>
        <tbody>
            <tr><td colspan="2">Salário Base</td><td class="text-end">' . number_format($salario_base, 2) . '</td></tr>
            <tr><td colspan="2">Subsídio de Transporte</td><td class="text-end">' . number_format($subsidio_transporte, 2) . '</td></tr>
            <tr><td colspan="2">Subsídio de Alimentação</td><td class="text-end">' . number_format($subsidio_alimentacao, 2) . '</td></tr>
            <tr><td colspan="2">Outros Vencimentos</td><td class="text-end">' . number_format($outros_vencimentos, 2) . '</td></tr>';
    
    if ($faltas_valor > 0) {
        $html .= '<tr><td colspan="2">(-) Faltas (' . $faltas_dias . ' dias)</td><td class="text-end text-danger">- ' . number_format($faltas_valor, 2) . '</td></tr>';
    }
    
    $html .= '
            <tr class="total-row"><td colspan="2"><strong>TOTAL VENCIMENTOS</strong></td><td class="text-end"><strong>' . number_format($total_vencimentos, 2) . '</strong></td></tr>
        </tbody>
     </table>
     
     <table>
        <thead>
            <tr><th colspan="2">DESCONTOS</th><th class="text-end">VALOR (Kz)</th></tr>
        </thead>
        <tbody>
            <tr><td colspan="2">INSS - Segurança Social</td><td class="text-end">' . number_format($inss, 2) . '</td></tr>
            <tr><td colspan="2">IRRF - Imposto de Renda</td><td class="text-end">' . number_format($irrf, 2) . '</td></tr>
            <tr class="total-row"><td colspan="2"><strong>TOTAL DESCONTOS</strong></td><td class="text-end"><strong>' . number_format($total_descontos, 2) . '</strong></td></tr>
        </tbody>
     </table>
     
     <table>
        <tr class="total-row"><td width="50%"><strong>SALÁRIO LÍQUIDO</strong></td><td class="text-end"><strong>' . number_format($salario_liquido, 2) . ' Kz</strong></td></tr>
        <tr><td colspan="2"><small>' . valorExtenso($salario_liquido) . '</small></td></tr>
     </table>
    
    <div class="assinatura">
        <div class="assinatura-linha"></div>
        <div>Assinatura e Carimbo da Empresa</div>
    </div>
    
    <div class="codigo-verificacao">
        Documento gerado eletronicamente por SIGE Angola em ' . $data_atual . '<br>
        Código de Verificação: ' . md5($funcionario['funcionario_id'] . $processamento_id . $salario_liquido) . '
    </div>
    
    <div class="rodape">
        Sistema Integrado de Gestão Escolar - SIGE Angola | Este documento é válido apenas com assinatura e carimbo da instituição.
    </div>
    
    </body>
    </html>';
    
    // Gerar PDF
    try {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        // Limpar o número do processo para usar no nome do arquivo
        $numero_processo_limpo = preg_replace('/[^a-zA-Z0-9]/', '_', $funcionario['numero_processo']);
        $nome_arquivo = 'holerite_' . $numero_processo_limpo . '_' . $ano . '_' . str_pad($processamento['mes'], 2, '0', STR_PAD_LEFT) . '.pdf';
        $caminho_completo = $upload_dir . $nome_arquivo;
        
        file_put_contents($caminho_completo, $dompdf->output());
        
        // Salvar no banco
        $stmt = $conn->prepare("
            INSERT INTO folha_holerites (escola_id, funcionario_id, processamento_id, ano, mes, salario_base, total_vencimentos, total_descontos, salario_liquido, caminho_pdf, codigo_verificacao, data_emissao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $escola_id, $funcionario['funcionario_id'], $processamento_id,
            $ano, $processamento['mes'],
            $salario_base, $total_vencimentos, $total_descontos, $salario_liquido,
            'uploads/holerites/' . $nome_arquivo,
            md5($funcionario['funcionario_id'] . $processamento_id . $salario_liquido)
        ]);
        
        $holerites_gerados[] = [
            'nome' => $funcionario['nome'],
            'arquivo' => $nome_arquivo,
            'caminho' => 'uploads/holerites/' . $nome_arquivo,
            'salario_liquido' => $salario_liquido
        ];
        
    } catch (Exception $e) {
        $erros[] = $funcionario['nome'] . ': ' . $e->getMessage();
    }
}

// Gerar página de resultados
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerar Holerites em Lote | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .container { max-width: 1200px; margin: 30px auto; }
        .card { border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2e; }
        .table-responsive { max-height: 400px; overflow-y: auto; }
        .info-card { background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 15px; }
        .info-card h4 { color: #006B3E; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-file-pdf"></i> Holerites Gerados em Lote</h3>
            </div>
            <div class="card-body">
                <!-- Informações do Processamento -->
                <div class="row mb-4">
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
                            <h4><?php echo count($funcionarios); ?></h4>
                            <small>Total de Funcionários</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card text-center">
                            <i class="fas fa-money-bill fa-2x text-warning"></i>
                            <h4><?php echo number_format($total_massa_salarial, 2); ?> Kz</h4>
                            <small>Massa Salarial</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-card text-center">
                            <i class="fas fa-folder-open fa-2x text-info"></i>
                            <h4><?php echo count($holerites_gerados); ?></h4>
                            <small>Holerites Gerados</small>
                        </div>
                    </div>
                </div>
                
                <?php if (count($erros) > 0): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Atenção:</strong> Alguns holerites não foram gerados:
                        <ul class="mb-0 mt-2">
                            <?php foreach ($erros as $erro): ?>
                                <li><?php echo htmlspecialchars($erro); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> 
                    <strong>Sucesso!</strong> Foram gerados <?php echo count($holerites_gerados); ?> holerites.
                </div>
                
                <!-- Botões de Ação -->
                <div class="text-center mb-4">
                    <?php if (count($holerites_gerados) > 1): ?>
                    <a href="zip_holerites.php?processamento_id=<?php echo $processamento_id; ?>" class="btn btn-warning btn-lg">
                        <i class="fas fa-file-archive"></i> Baixar Todos (ZIP)
                    </a>
                    <?php endif; ?>
                    <a href="processar.php?ano=<?php echo $ano; ?>&mes=<?php echo $processamento['mes']; ?>" class="btn btn-secondary btn-lg">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
                
                <!-- Tabela de Holerites -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-list"></i> Lista de Holerites Gerados
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="tabelaHolerites">
                                <thead class="table-light">
                                    <tr>
                                        <th>Funcionário</th>
                                        <th>Nº Processo</th>
                                        <th>Salário Líquido</th>
                                        <th>Data Emissão</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($holerites_gerados as $index => $hol): ?>
                                    <?php 
                                    $funcionario_atual = $funcionarios[$index] ?? null;
                                    $numero_processo = $funcionario_atual['numero_processo'] ?? 'N/A';
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($hol['nome']); ?></td>
                                        <td><?php echo htmlspecialchars($numero_processo); ?></td>
                                        <td><strong><?php echo number_format($hol['salario_liquido'], 2); ?> Kz</strong></td>
                                        <td><?php echo date('d/m/Y H:i:s'); ?></td>
                                        <td>
                                            <a href="../../<?php echo $hol['caminho']; ?>" target="_blank" class="btn btn-sm btn-primary" title="Download">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                            <a href="visualizar_holerite.php?arquivo=<?php echo urlencode($hol['arquivo']); ?>&processamento_id=<?php echo $processamento_id; ?>" target="_blank" class="btn btn-sm btn-info" title="Visualizar">
                                                <i class="fas fa-eye"></i> Visualizar
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Local dos Arquivos -->
                <div class="alert alert-secondary mt-3">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Local dos arquivos:</strong> 
                    <code><?php echo str_replace('\\', '/', realpath($upload_dir)); ?></code>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inicializar DataTable se necessário
        $(document).ready(function() {
            if ($.fn.DataTable) {
                $('#tabelaHolerites').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                    },
                    order: [[0, 'asc']],
                    pageLength: 25
                });
            }
        });
    </script>
</body>
</html>