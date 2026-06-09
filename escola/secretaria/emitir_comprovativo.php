<?php
// escola/alunos/emitir_comprovativo.php - Emitir Comprovativo de Matrícula

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_tipo = $_SESSION['usuario_tipo'] ?? '';

// Verificar se o ID do aluno foi passado
$estudante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($estudante_id <= 0) {
    die('ID do aluno inválido');
}

// ============================================
// VERIFICAR PERMISSÃO DE ACESSO
// ============================================
$acesso_permitido = false;

// Se for ADMIN ou SECRETARIA ou PROFESSOR, permite acesso a qualquer aluno
if (in_array($usuario_tipo, ['admin_escola', 'secretaria', 'professor', 'diretor'])) {
    $acesso_permitido = true;
} else {
    // Se for ALUNO, verifica se é o próprio
    $sql_check = "SELECT id FROM estudantes WHERE id = :estudante_id AND usuario_id = :usuario_id AND escola_id = :escola_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':estudante_id' => $estudante_id, ':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
    
    if ($stmt_check->fetch()) {
        $acesso_permitido = true;
    }
}

if (!$acesso_permitido) {
    die('Acesso negado. Você não tem permissão para visualizar este comprovativo.');
}

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql_aluno = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.data_nascimento,
        e.genero,
        e.email,
        e.telefone,
        e.endereco,
        e.foto,
        e.status as aluno_status,
        e.pais_nome,
        e.cidade_nome,
        e.provincia_nome,
        e.municipio_nome,
        e.comuna_nome,
        e.pai_nome,
        e.mae_nome,
        e.encarregado_nome,
        e.encarregado_parentesco,
        e.created_at as data_cadastro,
        es.nome as escola_nome,
        es.nome as escola_razao,
        es.logo as escola_logo,
        es.endereco as escola_endereco,
        es.telefone as escola_telefone,
        es.email as escola_email,
        es.nuit as escola_nuit,
        es.director as escola_diretor,
        es.secretario as escola_secretario
    FROM estudantes e
    LEFT JOIN escolas es ON es.id = e.escola_id
    WHERE e.id = :estudante_id AND e.escola_id = :escola_id
";

$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':estudante_id' => $estudante_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    die('Aluno não encontrado');
}

// ============================================
// BUSCAR MATRÍCULA ATIVA DO ALUNO
// ============================================
$sql_matricula = "
    SELECT 
        m.id,
        m.turma_id,
        m.turno,
        m.sala,
        m.classe,
        m.curso,
        m.nivel,
        m.ano_letivo,
        m.numero_processo,
        m.status as matricula_status,
        m.data_matricula,
        t.nome as turma_nome,
        t.ano as turma_ano
    FROM matriculas m
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE m.estudante_id = :estudante_id AND m.status = 'ativa'
    ORDER BY m.ano_letivo DESC
    LIMIT 1
";

$stmt_matricula = $conn->prepare($sql_matricula);
$stmt_matricula->execute([':estudante_id' => $estudante_id]);
$matricula = $stmt_matricula->fetch(PDO::FETCH_ASSOC);

if (!$matricula) {
    die('Nenhuma matrícula ativa encontrada para este aluno.');
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataExtenso($data) {
    if (empty($data)) return '';
    
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    
    $timestamp = strtotime($data);
    $dia = date('d', $timestamp);
    $mes = (int)date('m', $timestamp);
    $ano = date('Y', $timestamp);
    
    return $dia . ' de ' . $meses[$mes] . ' de ' . $ano;
}

// ============================================
// GERAR PDF
// ============================================

require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Configurar DOMPDF
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

// Preparar o HTML do comprovativo (mesmo código do comprovativo...)
$html = '<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Comprovativo de Matrícula - ' . htmlspecialchars($aluno['nome'] ?? '') . '</title>
    <style>
        @page {
            margin: 2cm;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            margin: 0;
            padding: 0;
            background: white;
            font-size: 12px;
        }
        .comprovativo-container {
            max-width: 100%;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 20px;
        }
        .logo-escola {
            max-height: 80px;
            max-width: 150px;
            margin-bottom: 10px;
        }
        .nome-escola {
            font-size: 20px;
            font-weight: bold;
            color: #006B3E;
            margin: 5px 0;
            text-transform: uppercase;
        }
        .contato-escola {
            font-size: 10px;
            color: #555;
            margin: 5px 0;
        }
        .titulo-principal {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin: 15px 0 5px 0;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .subtitulo {
            font-size: 12px;
            color: #666;
            text-align: center;
            margin-bottom: 20px;
        }
        .comprovativo-bordas {
            border: 2px solid #006B3E;
            padding: 30px;
            position: relative;
            margin-top: 20px;
        }
        .comprovativo-bordas:before {
            content: "";
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            bottom: 10px;
            border: 1px solid #006B3E;
            pointer-events: none;
        }
        .info-aluno {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .info-aluno td {
            padding: 8px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        .info-aluno td:first-child {
            width: 35%;
            font-weight: bold;
            background-color: #f5f5f5;
        }
        .info-matricula {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .info-matricula td {
            padding: 8px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        .info-matricula td:first-child {
            width: 35%;
            font-weight: bold;
            background-color: #f5f5f5;
        }
        .assinatura {
            margin-top: 40px;
            text-align: center;
        }
        .assinatura-linha {
            width: 200px;
            border-top: 1px solid #000;
            margin: 0 auto 10px auto;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #555;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .qrcode {
            text-align: center;
            margin-top: 20px;
        }
        .validade {
            font-size: 10px;
            color: #999;
            text-align: center;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="comprovativo-container">';

// Cabeçalho
$html .= '
        <div class="header">';
if (!empty($aluno['escola_logo']) && file_exists('../../uploads/escolas/logos/' . $aluno['escola_logo'])) {
    $html .= '<img src="../../uploads/escolas/logos/' . $aluno['escola_logo'] . '" class="logo-escola">';
}
$html .= '
            <div class="nome-escola">' . strtoupper(htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA')) . '</div>
            <div class="contato-escola">
                Email: ' . htmlspecialchars($aluno['escola_email'] ?? '') . ' | Telefone: ' . htmlspecialchars($aluno['escola_telefone'] ?? '') . '
            </div>
            <div class="titulo-principal">COMPROVATIVO DE MATRÍCULA</div>
            <div class="subtitulo">Documento de identificação escolar</div>
        </div>';

$html .= '
        <div class="comprovativo-bordas">';

// Dados do Aluno
$html .= '
            <table class="info-aluno">
                <tr><th colspan="2">DADOS DO ALUNO</th></tr>
                <tr><td width="35%">Nome Completo</td><td><strong>' . strtoupper(htmlspecialchars($aluno['nome'] ?? '')) . '</strong></td></tr>
                <tr><td>Data de Nascimento</td><td>' . formatarDataExtenso($aluno['data_nascimento'] ?? '') . '</td></tr>
                <tr><td>BI / Documento</td><td>' . htmlspecialchars($aluno['bi'] ?? '_______________') . '</td></tr>
                <tr><td>Género</td><td>' . ($aluno['genero'] == 'M' ? 'Masculino' : 'Feminino') . '</td></tr>
                <tr><td>Filiação</td><td>' . htmlspecialchars($aluno['pai_nome'] ?? '') . ' / ' . htmlspecialchars($aluno['mae_nome'] ?? '') . '</td></tr>
                <tr><td>Nacionalidade</td><td>' . htmlspecialchars($aluno['pais_nome'] ?? 'Angolana') . '</td></tr>
                <tr><td>Endereço</td><td>' . htmlspecialchars($aluno['endereco'] ?? '') . '</td></tr>
            </table>';

// Dados da Matrícula
$html .= '
            <table class="info-matricula" style="margin-top: 15px;">
                <tr><th colspan="2">DADOS DA MATRÍCULA</th></tr>
                <tr><td>Nº de Matrícula</td><td><strong>' . htmlspecialchars($matricula['numero_processo'] ?? $aluno['matricula'] ?? '') . '</strong></td></tr>
                <tr><td>Ano Letivo</td><td>' . htmlspecialchars($matricula['ano_letivo'] ?? '') . '</td></tr>
                <tr><td>Turma</td><td>' . htmlspecialchars($matricula['turma_nome'] ?? '-') . '</td></tr>
                <tr><td>Classe / Ano</td><td>' . htmlspecialchars($matricula['classe'] ?? '-') . '</td></tr>
                <tr><td>Turno</td><td>' . htmlspecialchars($matricula['turno'] ?? '-') . '</td></tr>
                <tr><td>Sala</td><td>' . htmlspecialchars($matricula['sala'] ?? '-') . '</td></tr>
                <tr><td>Curso</td><td>' . htmlspecialchars($matricula['curso'] ?? '-') . '</td></tr>
                <tr><td>Nível de Ensino</td><td>' . htmlspecialchars($matricula['nivel'] ?? '-') . '</td></tr>
                <tr><td>Data da Matrícula</td><td>' . formatarData($matricula['data_matricula'] ?? '') . '</td></tr>
                <tr><td>Status</td><td><span style="color: green; font-weight: bold;">' . strtoupper($matricula['matricula_status'] ?? 'ATIVA') . '</span></td></tr>
            </table>';

// Informações adicionais
$html .= '
            <div style="margin-top: 20px;">
                <p><strong>Observações:</strong></p>
                <p style="font-size: 10px; color: #666;">
                    - Este comprovativo é válido para o ano letivo em curso.<br>
                    - O aluno deve apresentar este documento sempre que solicitado pela direção da escola.<br>
                    - Em caso de perda, solicitar segunda via na secretaria.
                </p>
            </div>
        </div>';

// Assinaturas
$html .= '
        <div class="assinatura">
            <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                <div style="text-align: center; width: 45%;">
                    <div class="assinatura-linha"></div>
                    <p><strong>Director Pedagógico</strong></p>
                    <p>' . htmlspecialchars($aluno['escola_diretor'] ?? '_________________________') . '</p>
                </div>
                <div style="text-align: center; width: 45%;">
                    <div class="assinatura-linha"></div>
                    <p><strong>Secretário</strong></p>
                    <p>' . htmlspecialchars($aluno['escola_secretario'] ?? '_________________________') . '</p>
                </div>
            </div>
        </div>';

// QR Code e código de verificação
$html .= '
        <div class="qrcode">
            <div style="display: inline-block; text-align: center;">
                <div style="width: 80px; height: 80px; background: #f0f0f0; margin: 0 auto; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd;">
                    <i class="fas fa-qrcode fa-3x" style="color: #006B3E;"></i>
                </div>
                <div class="validade">
                    Código de verificação: <strong>' . strtoupper(substr(md5($aluno['id'] . $matricula['id']), 0, 10)) . '</strong>
                </div>
            </div>
        </div>';

// Rodapé
$html .= '
        <div class="footer">
            <div>' . htmlspecialchars($aluno['escola_endereco'] ?? '') . '</div>
            <div style="margin-top: 5px;">
                Documento emitido eletronicamente nos termos da legislação em vigor.
            </div>
            <div style="margin-top: 5px;">
                ' . htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA') . ' - Comprovativo emitido em ' . formatarDataExtenso(date('Y-m-d')) . '
            </div>
        </div>
    </div>
</body>
</html>';

// Gerar PDF
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo
$nome_arquivo = 'comprovativo_matricula_' . $aluno['matricula'] . '_' . date('Ymd') . '.pdf';

// Enviar para download
$dompdf->stream($nome_arquivo, array('Attachment' => true));
exit;
?>