<?php
// aluno/financeiro/gerar_nota_fiscal.php - Gerar Recibo/Fatura (Padrão Angola)

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();

if (!isset($_SESSION['aluno_id'])) {
    die('Acesso negado');
}

use Dompdf\Dompdf;
use Dompdf\Options;

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

$nota_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da nota fiscal e do pagamento
$sql = "SELECT 
            nf.*,
            e.nome as aluno_nome,
            e.matricula,
            e.matricula as aluno_nif,
            es.nome as escola_nome,
            es.nif as escola_nif,
            es.endereco as escola_endereco,
            es.telefone as escola_telefone,
            es.email as escola_email,
            es.logo as escola_logo,
            p.tipo_pagamento,
            p.metodo_pagamento,
            p.numero_fatura,
            p.data_pagamento
        FROM notas_fiscais nf
        JOIN estudantes e ON e.id = nf.aluno_id
        JOIN escolas es ON es.id = nf.escola_id
        LEFT JOIN pagamentos p ON p.id = nf.pagamento_id
        WHERE nf.id = :id AND nf.aluno_id = :aluno_id AND nf.escola_id = :escola_id";

$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $nota_id, ':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$dados = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dados) {
    die('Nota fiscal não encontrada.');
}

// Funções auxiliares
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function formatarData($data) {
    return date('d/m/Y H:i:s', strtotime($data));
}

// Gerar HTML do Recibo
$logo_html = '';
if (!empty($dados['escola_logo']) && file_exists(__DIR__ . '/../../../' . $dados['escola_logo'])) {
    $logo_data = base64_encode(file_get_contents(__DIR__ . '/../../../' . $dados['escola_logo']));
    $logo_html = '<img src="data:image/png;base64,' . $logo_data . '" style="max-width: 150px;">';
}

$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Recibo/Fatura - Escola</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #006B3E; margin-bottom: 20px; }
        .titulo { font-size: 24px; font-weight: bold; color: #006B3E; }
        .info-box { margin-bottom: 20px; }
        .info-box table { width: 100%; border-collapse: collapse; }
        .info-box td { padding: 5px; vertical-align: top; }
        .detalhes { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .detalhes th, .detalhes td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .detalhes th { background: #f2f2f2; }
        .total { font-size: 16px; font-weight: bold; text-align: right; margin-top: 20px; }
        .footer { margin-top: 40px; text-align: center; font-size: 10px; border-top: 1px solid #ddd; padding-top: 20px; }
        .qrcode { text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            ' . $logo_html . '
            <div class="titulo">RECIBO / FATURA</div>
            <p>' . htmlspecialchars($dados['escola_nome']) . '<br>
            NIF: ' . htmlspecialchars($dados['escola_nif']) . '<br>
            ' . htmlspecialchars($dados['escola_endereco']) . '</p>
        </div>

        <div class="info-box">
            <table>
                <tr><td width="50%"><strong>Cliente:</strong> ' . htmlspecialchars($dados['aluno_nome']) . '</td>
                    <td><strong>Data:</strong> ' . formatarData($dados['data_emissao']) . '</td></tr>
                <tr><td><strong>NIF/CI:</strong> ' . htmlspecialchars($dados['aluno_nif'] ?? '************') . '</td>
                    <td><strong>Matrícula:</strong> ' . htmlspecialchars($dados['matricula']) . '</td></tr>
                <tr><td><strong>Nº Documento:</strong> ' . htmlspecialchars($dados['numero_nota']) . '</td>
                    <td><strong>Série:</strong> ' . htmlspecialchars($dados['serie']) . '</td></tr>
            </table>
        </div>

        <table class="detalhes">
            <thead>
                <tr><th>Descrição</th><th>Valor (Kz)</th></tr>
            </thead>
            <tbody>
                <tr><td>' . htmlspecialchars(ucfirst($dados['tipo_pagamento'] ?? 'Pagamento')) . '</td>
                    <td>' . formatarMoeda($dados['valor']) . '</td></tr>
            </tbody>
        </table>

        <div class="total">
            <p>Valor Total: ' . formatarMoeda($dados['valor']) . '</p>
            <p>Forma de Pagamento: ' . htmlspecialchars(ucfirst($dados['metodo_pagamento'] ?? 'Dinheiro')) . '</p>
        </div>

        <div class="footer">
            <p>Documento emitido eletronicamente. Válido como comprovante de quitação.</p>
            <p>Código de Verificação: ' . md5($dados['id'] . $dados['chave_acesso']) . '</p>
        </div>
    </div>
</body>
</html>';

// Gerar PDF
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output do PDF forçando o download
$dompdf->stream("recibo_{$dados['numero_nota']}.pdf", array("Attachment" => 1));
exit;