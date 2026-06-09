<?php
// aluno/documentos/gerar_comprovativo_matricula.php - Gerar Comprovativo de Matrícula PDF

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Receber dados da requisição
$matricula_id = isset($_POST['matricula_id']) ? (int)$_POST['matricula_id'] : 0;

if ($matricula_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID da matrícula não informado']);
    exit;
}

// ============================================
// BUSCAR DADOS DA MATRÍCULA
// ============================================

$sql = "SELECT 
            m.id,
            m.ano_letivo,
            m.data_matricula,
            m.status as matricula_status,
            e.id as aluno_id,
            e.nome as aluno_nome,
            e.matricula,
            e.data_nascimento,
            e.telefone as aluno_telefone,
            e.endereco as aluno_endereco,
            e.bi,
            tur.id as turma_id,
            tur.nome as turma_nome,
            tur.ano as turma_ano,
            tur.sala,
            tur.turno,
            es.id as escola_id,
            es.nome as escola_nome,
            es.endereco as escola_endereco,
            es.telefone as escola_telefone,
            es.email as escola_email,
            es.logo as escola_logo,
            es.site as escola_site,
            r.nome as responsavel_nome,
            r.parentesco as responsavel_parentesco,
            r.telefone as responsavel_telefone,
            r.email as responsavel_email
        FROM matriculas m
        JOIN estudantes e ON e.id = m.estudante_id
        JOIN turmas tur ON tur.id = m.turma_id
        JOIN escolas es ON es.id = m.escola_id
        LEFT JOIN aluno_responsavel ar ON ar.aluno_id = e.id AND ar.principal = 1
        LEFT JOIN responsaveis r ON r.id = ar.responsavel_id
        WHERE m.id = :matricula_id 
        AND m.estudante_id = :aluno_id 
        AND m.escola_id = :escola_id";

$stmt = $conn->prepare($sql);
$stmt->execute([
    ':matricula_id' => $matricula_id,
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$dados = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dados) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Matrícula não encontrada']);
    exit;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataExtenso($data) {
    if (empty($data)) return '-';
    setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'portuguese');
    return strftime('%d de %B de %Y', strtotime($data));
}

function calcularIdade($data_nascimento) {
    if (empty($data_nascimento)) return '-';
    $idade = date_diff(date_create($data_nascimento), date_create('today'));
    return $idade->y . ' anos';
}

function getTurnoExtenso($turno) {
    $turnos = [
        'Manhã' => 'Matutino (07:30 às 11:50)',
        'Tarde' => 'Vespertino (13:30 às 17:50)',
        'Noite' => 'Noturno (18:30 às 22:30)'
    ];
    return $turnos[$turno] ?? $turno;
}

function getStatusMatricula($status) {
    $status_labels = [
        'ativa' => 'ATIVA',
        'trancada' => 'TRANCADA',
        'cancelada' => 'CANCELADA',
        'concluida' => 'CONCLUÍDA'
    ];
    return $status_labels[$status] ?? strtoupper($status);
}

function formatarNumero($numero) {
    return str_pad($numero, 6, '0', STR_PAD_LEFT);
}

// ============================================
// GERAR HTML DO COMPROVATIVO
// ============================================

$logo_html = '';
if (!empty($dados['escola_logo']) && file_exists(__DIR__ . '/../../../' . $dados['escola_logo'])) {
    $logo_path = __DIR__ . '/../../../' . $dados['escola_logo'];
    $logo_data = base64_encode(file_get_contents($logo_path));
    $logo_html = '<img src="data:image/png;base64,' . $logo_data . '" style="height: 80px;">';
}

$bi_info = '';
if (!empty($dados['bi'])) {
    $bi_info = '<td style="width: 50%;"><strong>BI:</strong> ' . htmlspecialchars($dados['bi']) . '</td>';
} else {
    $bi_info = '<td style="width: 50%;"><strong>BI:</strong> Não informado</td>';
}

$html = '
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Comprovativo de Matrícula</title>
    <style>
        @page {
            margin: 2cm;
            size: A4;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .container {
            max-width: 100%;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 20px;
        }
        .logo {
            margin-bottom: 10px;
        }
        .titulo {
            font-size: 24px;
            font-weight: bold;
            color: #006B3E;
            margin: 10px 0;
        }
        .subtitulo {
            font-size: 14px;
            color: #666;
        }
        .info-box {
            background: #f5f5f5;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            border-left: 4px solid #006B3E;
        }
        .info-box h4 {
            margin: 0 0 10px 0;
            color: #006B3E;
            font-size: 16px;
        }
        .detalhes {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }
        .detalhes td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .detalhes td:first-child {
            font-weight: bold;
            width: 35%;
            background: #f9f9f9;
        }
        .section-title {
            background: #006B3E;
            color: white;
            padding: 8px 12px;
            margin: 20px 0 15px 0;
            border-radius: 5px;
            font-size: 14px;
        }
        .status-ativo {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 5px 20px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
        }
        .assinaturas {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        .assinatura {
            display: inline-block;
            width: 45%;
            text-align: center;
        }
        .linha-assinatura {
            border-top: 1px solid #000;
            width: 80%;
            margin: 30px auto 5px auto;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
        .codigo-verificacao {
            background: #f0f0f0;
            padding: 10px;
            text-align: center;
            font-family: monospace;
            font-size: 14px;
            margin: 15px 0;
            border-radius: 5px;
        }
        .observacoes {
            margin-top: 20px;
            padding: 10px;
            background: #fff3cd;
            border-radius: 5px;
            font-size: 11px;
        }
        @media print {
            body { margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Cabeçalho -->
        <div class="header">
            <div class="logo">' . $logo_html . '</div>
            <div class="titulo">' . htmlspecialchars($dados['escola_nome']) . '</div>
            <div class="subtitulo">COMPROVATIVO DE MATRÍCULA</div>
        </div>
        
        <!-- Status -->
        <div style="text-align: center; margin-bottom: 20px;">
            <span class="status-ativo">✓ MATRÍCULA ' . getStatusMatricula($dados['matricula_status']) . '</span>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="info-box">
            <h4>DADOS DO ALUNO</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%;"><strong>Nome:</strong> ' . htmlspecialchars($dados['aluno_nome']) . '</td>
                    <td style="width: 50%;"><strong>Matrícula:</strong> ' . htmlspecialchars($dados['matricula']) . '</td>
                </tr>
                <tr>
                    <td style="width: 50%;"><strong>Data de Nascimento:</strong> ' . formatarData($dados['data_nascimento']) . ' (' . calcularIdade($dados['data_nascimento']) . ')</td>
                    ' . $bi_info . '
                </tr>
                <tr>
                    <td style="width: 50%;"><strong>Endereço:</strong> ' . htmlspecialchars($dados['aluno_endereco'] ?? 'Não informado') . '</td>
                    <td style="width: 50%;"><strong>Telefone:</strong> ' . htmlspecialchars($dados['aluno_telefone'] ?? 'Não informado') . '</td>
                </tr>
            </table>
        </div>
        
        <!-- Informações do Responsável -->
        <div class="info-box">
            <h4>DADOS DO RESPONSÁVEL</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%;"><strong>Nome:</strong> ' . htmlspecialchars($dados['responsavel_nome'] ?? 'Não informado') . '</td>
                    <td style="width: 50%;"><strong>Parentesco:</strong> ' . ucfirst($dados['responsavel_parentesco'] ?? 'Não informado') . '</td>
                </tr>
                <tr>
                    <td style="width: 50%;"><strong>Telefone:</strong> ' . htmlspecialchars($dados['responsavel_telefone'] ?? 'Não informado') . '</td>
                    <td style="width: 50%;"><strong>Email:</strong> ' . htmlspecialchars($dados['responsavel_email'] ?? 'Não informado') . '</td>
                </tr>
            </table>
        </div>
        
        <!-- Informações da Matrícula -->
        <div class="section-title">DADOS DA MATRÍCULA</div>
        <table class="detalhes">
            <tr>
                <td>Nº da Matrícula:</td>
                <td><strong>' . formatarNumero($dados['id']) . '/' . $dados['ano_letivo'] . '</strong></td>
            </tr>
            <tr>
                <td>Ano Letivo:</td>
                <td>' . $dados['ano_letivo'] . '</td>
            </tr>
            <tr>
                <td>Data da Matrícula:</td>
                <td>' . formatarDataExtenso($dados['data_matricula']) . '</td>
            </tr>
            <tr>
                <td>Turma:</td>
                <td>' . $dados['turma_ano'] . 'ª ' . htmlspecialchars($dados['turma_nome']) . ' (' . htmlspecialchars($dados['sala']) . ') ' . getTurnoExtenso($dados['turno']) . '</td>
            </tr>
        </table>
        
        <!-- Informações da Escola -->
        <div class="section-title">INFORMAÇÕES DA ESCOLA</div>
        <table class="detalhes">
            <tr>
                <td>Endereço:</td>
                <td>' . htmlspecialchars($dados['escola_endereco']) . '</td>
            </tr>
            <tr>
                <td>Telefone:</td>
                <td>' . htmlspecialchars($dados['escola_telefone']) . '</td>
            </tr>
            <tr>
                <td>Email:</td>
                <td>' . htmlspecialchars($dados['escola_email']) . '</td>
            </tr>' . 
            (!empty($dados['escola_site']) ? '<tr><td>Site:</td><td>' . htmlspecialchars($dados['escola_site']) . '</td></tr>' : '') . '
        </table>
        
        <!-- Código de Verificação -->
        <div class="codigo-verificacao">
            <strong>Código de Verificação:</strong> ' . md5($dados['id'] . $dados['data_matricula'] . $dados['aluno_nome'] . $dados['ano_letivo']) . '
        </div>
        
        <!-- Assinaturas -->
        <div class="assinaturas">
            <div style="display: flex; justify-content: space-between;">
                <div class="assinatura">
                    <div class="linha-assinatura"></div>
                    <div>Assinatura do Aluno/Responsável</div>
                </div>
                <div class="assinatura">
                    <div class="linha-assinatura"></div>
                    <div>Carimbo e Assinatura da Direção</div>
                </div>
            </div>
        </div>
        
        <!-- Observações -->
        <div class="observacoes">
            <strong>Observações Importantes:</strong><br>
            • Este comprovativo é válido como prova de matrícula para o ano letivo de ' . $dados['ano_letivo'] . '.<br>
            • O aluno está oficialmente matriculado na turma informada acima.<br>
            • Em caso de transferência, este documento deve ser apresentado na nova escola.<br>
            • Para segunda via, solicitar na secretaria da escola mediante pagamento de taxa.
        </div>
        
        <!-- Footer -->
        <div class="footer">
            Documento gerado eletronicamente em ' . date('d/m/Y H:i:s') . '<br>
            ' . htmlspecialchars($dados['escola_nome']) . ' - ' . htmlspecialchars($dados['escola_endereco']) . '<br>
            Telefone: ' . htmlspecialchars($dados['escola_telefone']) . ' | Email: ' . htmlspecialchars($dados['escola_email']) . '
        </div>
    </div>
</body>
</html>';

// ============================================
// GERAR PDF
// ============================================

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Salvar o PDF em arquivo
$upload_dir = __DIR__ . '/../../uploads/comprovativos/matriculas/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$nome_arquivo = 'comprovativo_matricula_' . $matricula_id . '_' . date('Ymd_His') . '.pdf';
$caminho_arquivo = 'uploads/comprovativos/matriculas/' . $nome_arquivo;
$caminho_completo = $upload_dir . $nome_arquivo;

file_put_contents($caminho_completo, $dompdf->output());

// Retornar resposta JSON
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'arquivo' => $caminho_arquivo,
    'message' => 'Comprovativo de matrícula gerado com sucesso!'
]);
?>