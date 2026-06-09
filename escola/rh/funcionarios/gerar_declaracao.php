<?php
// escola/rh/funcionarios/gerar_declaracao.php - Gerar Declaração de Funcionário em PDF
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

// Carregar DOMPDF via Composer
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$id = $_GET['id'] ?? 0;

// Buscar dados do funcionário
$stmt = $conn->prepare("
    SELECT f.*, u.email as user_email
    FROM funcionarios f 
    LEFT JOIN usuarios u ON f.usuario_id = u.id 
    WHERE f.id = ? AND f.escola_id = ?
");
$stmt->execute([$id, $escola_id]);
$funcionario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die("Funcionário não encontrado.");
}

// Buscar dados da escola
$stmt = $conn->prepare("SELECT * FROM escolas WHERE id = ?");
$stmt->execute([$escola_id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar chefe do RH (usuário com permissão de RH ou admin)
$stmt = $conn->prepare("
    SELECT u.nome, f.cargo 
    FROM usuarios u 
    LEFT JOIN funcionarios f ON f.usuario_id = u.id
    WHERE u.escola_id = ? AND (u.tipo = 'admin' OR u.tipo = 'rh' OR u.tipo = 'funcionario') 
    LIMIT 1
");
$stmt->execute([$escola_id]);
$chefe_rh = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$chefe_rh) {
    $chefe_rh = ['nome' => '_____________________', 'cargo' => 'Chefe do Recursos Humanos'];
}

// Tipo de declaração
$tipo_declaracao = $_GET['tipo'] ?? 'funcionario';
$data_atual = date('d/m/Y');
$data_extenso = date('d') . ' de ' . obterMesExtenso(date('m')) . ' de ' . date('Y');

// Função para obter mês em extenso
function obterMesExtenso($mes) {
    $meses = [
        '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
        '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
        '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
    ];
    return $meses[$mes];
}

// Função para criar diretórios recursivamente
function criarDiretorio($caminho) {
    if (!file_exists($caminho)) {
        return mkdir($caminho, 0777, true);
    }
    return true;
}

// Gerar conteúdo HTML do PDF
$html = '
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Declaração - ' . htmlspecialchars($funcionario['nome']) . '</title>
    <style>
        @page {
            margin: 2.5cm;
            size: A4;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 15px;
        }
        
        .logo {
            font-size: 24pt;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 5px;
        }
        
        .escola-nome {
            font-size: 18pt;
            font-weight: bold;
            color: #1A2A6C;
            margin-bottom: 5px;
        }
        
        .escola-info {
            font-size: 10pt;
            color: #666;
            margin-bottom: 3px;
        }
        
        .titulo {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 30px 0 20px 0;
            text-decoration: underline;
        }
        
        .conteudo {
            text-align: justify;
            margin: 20px 0;
            line-height: 2;
        }
        
        .assinatura {
            margin-top: 50px;
            text-align: center;
        }
        
        .assinatura-linha {
            margin-top: 30px;
            width: 250px;
            border-top: 1px solid #000;
            margin-left: auto;
            margin-right: auto;
        }
        
        .assinatura-nome {
            font-weight: bold;
            margin-top: 5px;
        }
        
        .assinatura-cargo {
            font-size: 10pt;
            color: #666;
        }
        
        .rodape {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .info-funcionario {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .info-label {
            font-weight: bold;
            width: 150px;
            display: inline-block;
        }
        
        .data-local {
            text-align: right;
            margin-bottom: 20px;
        }
        
        .texto-declaracao {
            text-indent: 50px;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="logo">SIGE Angola</div>
    <div class="escola-nome">' . htmlspecialchars($escola['nome'] ?? 'Escola') . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['provincia'] ?? '') . ' - ' . htmlspecialchars($escola['municipio'] ?? '') . '</div>
    <div class="escola-info">Telefone: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</div>
</div>

<div class="data-local">
    Luanda, ' . $data_extenso . '
</div>

<div class="titulo">
    DECLARAÇÃO
</div>

<div class="conteudo">
    <p class="texto-declaracao">
        Eu, <strong>' . htmlspecialchars($chefe_rh['nome']) . '</strong>, na qualidade de <strong>' . htmlspecialchars($chefe_rh['cargo']) . '</strong> da instituição 
        <strong>' . htmlspecialchars($escola['nome'] ?? 'Escola') . '</strong>, venho por este meio declarar que 
        <strong>' . htmlspecialchars($funcionario['nome']) . '</strong>, ';
        
        if ($funcionario['bi']) {
            $html .= 'portador(a) do Bilhete de Identidade n° <strong>' . htmlspecialchars($funcionario['bi']) . '</strong>, ';
        }
        
        $html .= 'é funcionário(a) desta instituição desde <strong>' . date('d/m/Y', strtotime($funcionario['data_admissao'])) . '</strong>, ';
        $html .= 'exercendo atualmente a função de <strong>' . htmlspecialchars($funcionario['cargo']) . '</strong>.';
    $html .= '
    </p>
    
    <p class="texto-declaracao">
        O(A) referido(a) funcionário(a) exerce suas atividades laborais no regime de contrato 
        <strong>' . htmlspecialchars($funcionario['tipo_contrato']) . '</strong>.
    </p>
    
    <p class="texto-declaracao">
        Esta declaração é emitida a pedido do(a) interessado(a) para os devidos fins, 
        confirmando a veracidade das informações acima descritas.
    </p>
</div>

<div class="info-funcionario">
    <strong>Informações do Funcionário:</strong><br>
    <span class="info-label">Nº Processo:</span> ' . htmlspecialchars($funcionario['numero_processo']) . '<br>
    <span class="info-label">Cargo:</span> ' . htmlspecialchars($funcionario['cargo']) . '<br>
    <span class="info-label">Data Admissão:</span> ' . date('d/m/Y', strtotime($funcionario['data_admissao'])) . '<br>
    <span class="info-label">Tipo Contrato:</span> ' . htmlspecialchars($funcionario['tipo_contrato']) . '<br>
    <span class="info-label">Habilitação:</span> ' . htmlspecialchars($funcionario['habilitacao']) . '
</div>

<div class="assinatura">
    <div class="assinatura-linha"></div>
    <div class="assinatura-nome">' . htmlspecialchars($chefe_rh['nome']) . '</div>
    <div class="assinatura-cargo">' . htmlspecialchars($chefe_rh['cargo']) . '</div>
    <div class="assinatura-cargo">Assinatura e Carimbo</div>
</div>

<div class="rodape">
    Sistema Integrado de Gestão Escolar - SIGE Angola | Documento gerado eletronicamente em ' . $data_atual . ' às ' . date('H:i:s') . '<br>
    Este documento é válido apenas com assinatura e carimbo da instituição.
</div>

</body>
</html>
';

// Se for solicitação de visualização (não gerar PDF)
if (isset($_GET['view']) && $_GET['view'] == 'html') {
    echo $html;
    exit;
}

// Configurar DOMPDF
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo - remover caracteres especiais
$numero_processo_limpo = preg_replace('/[^a-zA-Z0-9]/', '_', $funcionario['numero_processo']);
$nome_arquivo = 'declaracao_' . $numero_processo_limpo . '_' . date('Ymd_His') . '.pdf';

// Criar diretório de uploads se não existir
$base_upload_dir = __DIR__ . '/../../../uploads/';
$declaracoes_dir = $base_upload_dir . 'declaracoes/';

// Criar diretórios recursivamente
if (!is_dir($base_upload_dir)) {
    mkdir($base_upload_dir, 0777, true);
}
if (!is_dir($declaracoes_dir)) {
    mkdir($declaracoes_dir, 0777, true);
}

$pdf_path = $declaracoes_dir;
$pdf_file = $pdf_path . $nome_arquivo;

// Salvar o arquivo PDF
try {
    $output = $dompdf->output();
    file_put_contents($pdf_file, $output);
    $pdf_url = '../../../uploads/declaracoes/' . $nome_arquivo;
    $salvo_com_sucesso = true;
} catch (Exception $e) {
    $salvo_com_sucesso = false;
    $erro_msg = $e->getMessage();
}

// Se for download
if (isset($_GET['download'])) {
    $dompdf->stream($nome_arquivo, array('Attachment' => true));
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerar Declaração | SIGE Angola</title>
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
        .btn-pdf { background: #dc3545; border: none; }
        .btn-pdf:hover { background: #c82333; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .preview-pdf { width: 100%; height: 500px; border: 1px solid #ddd; border-radius: 10px; }
        .info-funcionario { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
    </style>
</head>
<body>
  
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-file-pdf"></i> Gerar Declaração</h2>
            <a href="visualizar.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-info-circle"></i> Informações do Funcionário
                    </div>
                    <div class="card-body">
                        <div class="info-funcionario">
                            <p><strong><i class="fas fa-user"></i> Nome:</strong> <?php echo htmlspecialchars($funcionario['nome']); ?></p>
                            <p><strong><i class="fas fa-id-card"></i> Nº Processo:</strong> <?php echo htmlspecialchars($funcionario['numero_processo']); ?></p>
                            <p><strong><i class="fas fa-briefcase"></i> Cargo:</strong> <?php echo htmlspecialchars($funcionario['cargo']); ?></p>
                            <p><strong><i class="fas fa-calendar"></i> Admissão:</strong> <?php echo date('d/m/Y', strtotime($funcionario['data_admissao'])); ?></p>
                            <p><strong><i class="fas fa-file-contract"></i> Contrato:</strong> <?php echo htmlspecialchars($funcionario['tipo_contrato']); ?></p>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Documento gerado eletronicamente</strong><br>
                            <small>O PDF será gerado no formato A4 com layout profissional.</small>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <a href="?id=<?php echo $id; ?>&download=1" class="btn btn-pdf btn-lg">
                                <i class="fas fa-download"></i> Baixar PDF
                            </a>
                            <a href="?id=<?php echo $id; ?>&view=html" target="_blank" class="btn btn-info btn-lg">
                                <i class="fas fa-eye"></i> Visualizar HTML
                            </a>
                            <?php if ($salvo_com_sucesso && file_exists($pdf_file)): ?>
                            <a href="<?php echo $pdf_url; ?>" target="_blank" class="btn btn-primary btn-lg">
                                <i class="fas fa-file-pdf"></i> Abrir PDF
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card mt-3">
                    <div class="card-header">
                        <i class="fas fa-cog"></i> Opções
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-3">
                            <label>Tipo de Declaração</label>
                            <select id="tipo_declaracao" class="form-control">
                                <option value="funcionario">Declaração de Funcionário</option>
                                <option value="salario">Declaração de Salário</option>
                                <option value="cargo">Declaração de Cargo</option>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label>Formato</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="formato" id="formatoA4" checked>
                                <label class="form-check-label" for="formatoA4">A4 (210 x 297 mm)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="formato" id="formatoCarta">
                                <label class="form-check-label" for="formatoCarta">Carta (216 x 279 mm)</label>
                            </div>
                        </div>
                        <hr>
                        <div class="alert alert-warning small">
                            <i class="fas fa-gavel"></i> 
                            <strong>Base Legal:</strong> Lei Geral do Trabalho (Lei 7/15, de 15 de Junho)
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-file-pdf"></i> Pré-visualização do Documento
                    </div>
                    <div class="card-body text-center">
                        <?php if ($salvo_com_sucesso && file_exists($pdf_file)): ?>
                            <iframe src="<?php echo $pdf_url; ?>" class="preview-pdf" frameborder="0"></iframe>
                            <div class="mt-3">
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> 
                                    PDF gerado com sucesso! Clique nos botões ao lado para baixar ou visualizar.
                                </div>
                                <div class="alert alert-secondary small">
                                    <i class="fas fa-info-circle"></i> 
                                    <strong>Local do arquivo:</strong> <?php echo str_replace('\\', '/', $pdf_file); ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> 
                                <strong>PDF gerado com sucesso!</strong><br>
                                Clique no botão "Baixar PDF" para fazer o download do documento.
                                <?php if (isset($erro_msg)): ?>
                                <br><small class="text-danger">Detalhe: <?php echo $erro_msg; ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="mt-3">
                                <a href="?id=<?php echo $id; ?>&download=1" class="btn btn-danger btn-lg">
                                    <i class="fas fa-download"></i> Baixar PDF
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <i class="fas fa-code"></i> Instalação da Biblioteca DOMPDF
            </div>
            <div class="card-body">
                <h5>Como instalar o DOMPDF:</h5>
                <ol>
                    <li><strong>Via Composer (Recomendado):</strong>
                        <pre class="bg-light p-2 rounded"><code>cd C:\xampp\htdocs\sige_Plataforma
composer require dompdf/dompdf</code></pre>
                    </li>
                    <li><strong>Download Manual:</strong>
                        <ul>
                            <li>Acesse: <a href="https://github.com/dompdf/dompdf/releases" target="_blank">https://github.com/dompdf/dompdf/releases</a></li>
                            <li>Baixe a versão mais recente (dompdf-x.x.x.zip)</li>
                            <li>Extraia para: <code>C:\xampp\htdocs\sige_Plataforma\vendor\dompdf\</code></li>
                        </ul>
                    </li>
                    <li><strong>Verificar instalação:</strong>
                        <pre class="bg-light p-2 rounded"><code>// Arquivo: teste_dompdf.php
&lt;?php
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;
$dompdf = new Dompdf();
echo "DOMPDF instalado com sucesso!";
?&gt;</code></pre>
                    </li>
                </ol>
                <div class="alert alert-info">
                    <i class="fas fa-terminal"></i> 
                    <strong>Estrutura esperada após instalação:</strong><br>
                    <code>C:\xampp\htdocs\sige_Plataforma\vendor\dompdf\dompdf\src\Dompdf.php</code>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        // Alterar tipo de declaração
        $('#tipo_declaracao').change(function() {
            var tipo = $(this).val();
            window.location.href = 'gerar_declaracao.php?id=<?php echo $id; ?>&tipo=' + tipo;
        });
        
        // Alterar formato
        $('input[name="formato"]').change(function() {
            var formato = $(this).attr('id') == 'formatoA4' ? 'A4' : 'Carta';
            // Recarregar com novo formato (implementar se necessário)
        });
    </script>
</body>
</html>