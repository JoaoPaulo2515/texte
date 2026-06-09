<?php
// escola/secretaria/recibo_matricula.php - Gerar Recibo de Reconfirmação de Matrícula

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar se o ID do aluno foi passado
$aluno_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($aluno_id <= 0) {
    die('ID do aluno inválido');
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
        e.telefone,
        e.endereco,
        e.pai_nome,
        e.mae_nome,
        e.encarregado_nome,
        e.encarregado_telefone,
        e.encarregado_email,
        e.provincia_nome,
        e.cidade_nome,
        es.nome as escola_nome,
        es.nome as escola_razao,
        es.logo as escola_logo,
        es.endereco as escola_endereco,
        es.telefone as escola_telefone,
        es.email as escola_email,
        es.nuit as escola_nuit
    FROM estudantes e
    LEFT JOIN escolas es ON es.id = e.escola_id
    WHERE e.id = :aluno_id AND e.escola_id = :escola_id
";

$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
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
    WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
    ORDER BY m.ano_letivo DESC
    LIMIT 1
";

$stmt_matricula = $conn->prepare($sql_matricula);
$stmt_matricula->execute([':aluno_id' => $aluno_id]);
$matricula = $stmt_matricula->fetch(PDO::FETCH_ASSOC);

// ============================================
// GERAR NÚMERO DE PROCESSO E RECIBO
// ============================================
$numero_processo = $aluno['matricula'] ?? rand(100000, 999999);
$numero_recibo = rand(1, 100) . '/' . date('Y');

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarData($data) {
    if (empty($data)) return '__ / __ / ____';
    return date('d / m / Y', strtotime($data));
}

function formatarDataExtenso($data) {
    if (empty($data)) $data = date('Y-m-d');
    
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

// Preparar o HTML do recibo
$html = '<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Recibo de Reconfirmação de Matrícula</title>
    <style>
        @page {
            margin: 2cm;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            margin: 0;
            padding: 0;
            background: white;
            font-size: 10px;
        }
        .recibo-container {
            max-width: 100%;
            padding: 10px;
        }
        .cabecalho-ministerio {
            text-align: center;
            margin-bottom: 10px;
        }
        .ministerio {
            font-size: 9px;
            text-transform: uppercase;
            margin: 0;
        }
        .escola-nome {
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            margin: 5px 0;
            text-transform: uppercase;
        }
        .escola-endereco {
            font-size: 8px;
            text-align: center;
            color: #555;
            margin: 2px 0;
        }
        .titulo-recibo {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            margin: 10px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .recibo-numero {
            text-align: right;
            font-size: 10px;
            margin-bottom: 15px;
        }
        .secao-titulo {
            font-size: 11px;
            font-weight: bold;
            margin: 10px 0 5px 0;
            text-decoration: underline;
        }
        .info-linha {
            margin: 3px 0;
        }
        .info-label {
            font-weight: bold;
        }
        .info-inline {
            display: inline-block;
            margin-right: 15px;
        }
        .dados-biograficos {
            border: 1px solid #ccc;
            padding: 8px;
            margin: 10px 0;
        }
        .dados-estudante {
            border: 1px solid #ccc;
            padding: 8px;
            margin: 10px 0;
        }
        .documento-entregue {
            margin: 10px 0;
        }
        .situacao-saude {
            margin: 10px 0;
        }
        .compromisso {
            margin: 15px 0;
            padding: 8px;
            border: 1px solid #ccc;
            background: #f9f9f9;
            font-size: 9px;
            text-align: justify;
        }
        .assinaturas {
            margin-top: 30px;
            text-align: center;
        }
        .assinatura-linha {
            display: inline-block;
            width: 200px;
            border-top: 1px solid #000;
            margin: 0 30px;
        }
        .assinatura-texto {
            display: inline-block;
            width: 200px;
            text-align: center;
            margin: 5px 30px;
            font-size: 9px;
        }
        .rodape {
            margin-top: 20px;
            text-align: center;
            font-size: 8px;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 8px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .bold { font-weight: bold; }
        .mt-20 { margin-top: 20px; }
        .mb-10 { margin-bottom: 10px; }
        .inline-block { display: inline-block; }
        .col-4 { width: 30%; display: inline-block; vertical-align: top; }
        .clearfix { clear: both; }
    </style>
</head>
<body>
    <div class="recibo-container">';

// Cabeçalho com Ministério da Educação
$html .= '
        <div class="cabecalho-ministerio">
            <div class="ministerio">República de Angola</div>
            <div class="ministerio">Ministério da Educação</div>
            <div class="escola-nome">' . strtoupper(htmlspecialchars($aluno['escola_nome'] ?? 'COMPLEXO ESCOLAR IB Nº 3044')) . '</div>
           </div>';

// Título do Recibo
$html .= '
        <div class="titulo-recibo">RECIBO DE RECONFIRMAÇÃO DE MATRÍCULA</div>
        <div class="recibo-numero">Nº ' . $numero_recibo . '</div>';

// DADOS BIOGRÁFICOS
$html .= '
        <div class="secao-titulo">DADOS BIOGRÁFICOS</div>
        <div class="dados-biograficos">
            <div class="info-linha">
                <span class="info-label">Nome estudante:</span> ' . strtoupper(htmlspecialchars($aluno['nome'] ?? '')) . '
                <span style="margin-left: 20px;"><span class="info-label">Genero:</span> ' . ($aluno['genero'] == 'M' ? 'Masculino' : 'Feminino') . '</span>
                <span style="margin-left: 20px;"><span class="info-label">Nascimento:</span> ' . formatarData($aluno['data_nascimento'] ?? '') . '</span>
            </div>
            <div class="info-linha">
                <span class="info-label">Naturalidade:</span> ' . htmlspecialchars($aluno['cidade_nome'] ?? '') . '
                <span style="margin-left: 20px;"><span class="info-label">Província:</span> ' . htmlspecialchars($aluno['provincia_nome'] ?? '') . '</span>
                <span style="margin-left: 20px;"><span class="info-label">Nº BI/Cédula:</span> ' . htmlspecialchars($aluno['bi'] ?? '') . '</span>
            </div>
            <div class="info-linha">
                <span class="info-label">Emissão:</span> ' . formatarData($aluno['bi_data_emissao'] ?? '') . '
                <span style="margin-left: 20px;"><span class="info-label">Pai:</span> ' . htmlspecialchars($aluno['pai_nome'] ?? '') . '</span>
                <span style="margin-left: 20px;"><span class="info-label">Mãe:</span> ' . htmlspecialchars($aluno['mae_nome'] ?? '') . '</span>
            </div>
        </div>';

// DADOS ESTUDANTE
$html .= '
        <div class="secao-titulo">DADOS ESTUDANTE</div>
        <div class="dados-estudante">
            <div class="info-linha">
                <span class="info-label">Nº Processo:</span> ' . $numero_processo . '
                <span style="margin-left: 20px;"><span class="info-label">Classe:</span> ' . htmlspecialchars($matricula['classe'] ?? $aluno['classe'] ?? '3ª Classe') . '</span>
                <span style="margin-left: 20px;"><span class="info-label">Sala:</span> ' . htmlspecialchars($matricula['sala'] ?? '12') . '</span>
                <span style="margin-left: 20px;"><span class="info-label">Turma:</span> ' . htmlspecialchars($matricula['turma_nome'] ?? '3ª CM/2025-2026') . '</span>
            </div>
            <div class="info-linha">
                <span class="info-label">Curso:</span> ' . htmlspecialchars($matricula['curso'] ?? 'Ensino Primário Pré a 4ª Classe') . '
                <span style="margin-left: 20px;"><span class="info-label">Período:</span> ' . ucfirst(htmlspecialchars($matricula['turno'] ?? 'Manhã')) . '</span>
            </div>
            <div class="info-linha">
                <span class="info-label">Encarregado:</span> ' . htmlspecialchars($aluno['encarregado_nome'] ?? '') . '
                <span style="margin-left: 20px;"><span class="info-label">Profissão:</span> ' . htmlspecialchars($aluno['encarregado_email'] ?? '') . '</span>
                <span style="margin-left: 20px;"><span class="info-label">Contacto:</span> ' . htmlspecialchars($aluno['encarregado_telefone'] ?? $aluno['telefone'] ?? '') . '</span>
                <span style="margin-left: 20px;"><span class="info-label">Outro:</span></span>
            </div>
            <div class="info-linha">
                <span class="info-label">Morada(Estudante):</span> ' . htmlspecialchars($aluno['endereco'] ?? '') . '
            </div>
        </div>';

// COMPROMISSO
$html .= '
        <div class="compromisso">
            <strong>COMPROMISSO:</strong> Comprometo-me a regularizar as propinas até aos dias 15 do primeiro mês do trimestre a estudar, findo o prazo deverei pagar uma multa, por mês, de 500 kz , atrazo superior a 30 dias implica suspenção das aulas.
        </div>';

// Assinaturas
$html .= '
        <div class="assinaturas">
            <div style="margin-bottom: 5px;">
                ' . htmlspecialchars($aluno['escola_nome'] ?? 'Complexo Escolar IB nº 3044') . '  aos ' . formatarDataExtenso(date('Y-m-d')) . '
            </div>
            <div style="margin-top: 10px;">
                <div class="assinatura-linha"></div>
                <div class="assinatura-linha"></div>
            </div>
            <div>
                <span class="assinatura-texto">O(A) Encarregado(a)</span>
                <span class="assinatura-texto">O(A) Funcionário(a)</span>
            </div>
        </div>';

// Rodapé (segunda via do recibo)
$html .= '
        <div style="margin-top: 40px; border-top: 1px dashed #ccc; padding-top: 20px;">';

// Segunda via do Recibo
$html .= '
            <div class="cabecalho-ministerio">
                <div class="ministerio">República de Angola</div>
                <div class="ministerio">Ministério da Educação</div>
                <div class="escola-nome">' . strtoupper(htmlspecialchars($aluno['escola_nome'] ?? 'COMPLEXO ESCOLAR IB Nº 3044')) . '</div>
             </div>
            <div class="titulo-recibo">RECIBO DE RECONFIRMAÇÃO DE MATRÍCULA</div>
            <div class="recibo-numero">Nº ' . $numero_recibo . '</div>';

// Dados da segunda via
$html .= '
            <div class="dados-estudante">
                <div class="info-linha">
                    <span class="info-label">Nº Processo:</span> ' . $numero_processo . '
                    <span style="margin-left: 20px;"><span class="info-label">Nome estudante:</span> ' . strtoupper(htmlspecialchars($aluno['nome'] ?? '')) . '</span>
                </div>
                <div class="info-linha">
                    <span class="info-label">Classe:</span> ' . htmlspecialchars($matricula['classe'] ?? $aluno['classe'] ?? '3ª Classe') . '
                    <span style="margin-left: 20px;"><span class="info-label">Sala:</span> ' . htmlspecialchars($matricula['sala'] ?? '12') . '</span>
                    <span style="margin-left: 20px;"><span class="info-label">Turma:</span> ' . htmlspecialchars($matricula['turma_nome'] ?? '3ª CM/2025-2026') . '</span>
                    <span style="margin-left: 20px;"><span class="info-label">Período:</span> ' . ucfirst(htmlspecialchars($matricula['turno'] ?? 'Manhã')) . '</span>
                </div>
                <div class="info-linha">
                    <span class="info-label">Curso:</span> ' . htmlspecialchars($matricula['curso'] ?? 'Ensino Primário Pré a 4ª Classe') . '
                </div>
            </div>';

// Compromisso segunda via
$html .= '
            <div class="compromisso">
                <strong>COMPROMISSO:</strong> Comprometo-me a regularizar as propinas até aos dias 15 do primeiro mês do trimestre a estudar, findo o prazo deverei pagar uma multa, por mês, de 500 kz , atrazo superior a 30 dias implica suspenção das aulas.
            </div>';

// Assinaturas segunda via
$html .= '
            <div class="assinaturas">
                <div style="margin-bottom: 15px;">
                    ' . htmlspecialchars($aluno['escola_nome'] ?? 'Complexo Escolar IB nº 3044') . ' \'' . htmlspecialchars($aluno['escola_razao'] ?? 'São Francisco de Assis') . '\' aos ' . formatarDataExtenso(date('Y-m-d')) . '
                </div>
                <div style="margin-top: 20px;">
                    <div class="assinatura-linha"></div>
                    <div class="assinatura-linha"></div>
                </div>
                <div>
                    <span class="assinatura-texto">O(A) Funcionário(a)</span>
                    <span class="assinatura-texto">O(A) Encarregado(a)</span>
                </div>
            </div>';

$html .= '
        </div>';

$html .= '
    </div>
</body>
</html>';

// Gerar PDF
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo
$nome_arquivo = 'recibo_matricula_' . $aluno['matricula'] . '_' . date('Ymd') . '.pdf';

// Enviar para download
$dompdf->stream($nome_arquivo, array('Attachment' => true));
exit;
?>