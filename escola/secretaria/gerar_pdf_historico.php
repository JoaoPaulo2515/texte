<?php
// escola/secretaria/gerar_pdf_historico.php - Gerar PDF do Histórico Escolar

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
$estudante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($estudante_id <= 0) {
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
        e.pai_bi,
        e.pai_telefone,
        e.pai_profissao,
        e.mae_nome,
        e.mae_bi,
        e.mae_telefone,
        e.mae_profissao,
        e.encarregado_nome,
        e.encarregado_parentesco,
        e.encarregado_bi,
        e.encarregado_telefone,
        e.encarregado_email,
        e.encarregado_endereco,
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
// BUSCAR TODAS AS MATRÍCULAS DO ALUNO
// ============================================
$sql_matriculas = "
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
        m.created_at,
        t.nome as turma_nome,
        t.ano as turma_ano
    FROM matriculas m
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE m.estudante_id = :estudante_id
    ORDER BY m.ano_letivo DESC, m.created_at DESC
";

$stmt_matriculas = $conn->prepare($sql_matriculas);
$stmt_matriculas->execute([':estudante_id' => $estudante_id]);
$matriculas = $stmt_matriculas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR TODAS AS NOTAS DO ALUNO POR ANO LETIVO
// ============================================
$sql_notas = "
    SELECT 
        n.id,
        n.disciplina_id,
        n.bimestre,
        n.mac,
        n.npt,
        n.exame_normal,
        n.exame_recurso,
        n.exame_especial,
        n.media_final,
        n.status as nota_status,
        n.created_at as data_lancamento,
        d.nome as disciplina_nome,
        al.ano as ano_letivo,
        al.id as ano_letivo_id,
        p.nome as professor_nome
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    INNER JOIN ano_letivo al ON al.id = n.ano_letivo_id
    LEFT JOIN funcionarios p ON p.id = n.professor_id
    WHERE n.estudante_id = :estudante_id
    ORDER BY al.ano DESC, n.bimestre, d.nome
";

$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':estudante_id' => $estudante_id]);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

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

function getMediaGeralPorAno($notas, $ano) {
    $notas_ano = array_filter($notas, function($nota) use ($ano) {
        return $nota['ano_letivo'] == $ano;
    });
    if (empty($notas_ano)) return 0;
    $soma = 0;
    foreach ($notas_ano as $nota) {
        $soma += $nota['media_final'] ?? 0;
    }
    return round($soma / count($notas_ano), 1);
}

function getSituacaoPorMedia($media) {
    if ($media >= 14) {
        return ['texto' => 'Muito Bom', 'classe' => 'muito_bom'];
    } elseif ($media >= 10) {
        return ['texto' => 'Bom', 'classe' => 'bom'];
    } elseif ($media >= 7) {
        return ['texto' => 'Suficiente', 'classe' => 'suficiente'];
    } else {
        return ['texto' => 'Insuficiente', 'classe' => 'insuficiente'];
    }
}

function getNotaPorExtenso($nota) {
    if ($nota >= 18) return 'Excelente';
    if ($nota >= 14) return 'Muito Bom';
    if ($nota >= 10) return 'Bom';
    if ($nota >= 7) return 'Suficiente';
    return 'Insuficiente';
}

// Agrupar notas por ano letivo
$notas_por_ano = [];
foreach ($notas as $nota) {
    $ano = $nota['ano_letivo'];
    if (!isset($notas_por_ano[$ano])) {
        $notas_por_ano[$ano] = [];
    }
    $notas_por_ano[$ano][] = $nota;
}

// Converter foto para base64 se existir
$foto_base64 = '';
if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])) {
    $foto_data = file_get_contents('../../uploads/alunos/fotos/' . $aluno['foto']);
    $foto_base64 = 'data:image/jpeg;base64,' . base64_encode($foto_data);
}

// Nome da escola para o rodapé
$escola_nome_rodape = htmlspecialchars($aluno['escola_nome'] ?? 'Colégio Pombal');
$escola_numero = htmlspecialchars($aluno['nuit'] ?? 'Nº 4324');

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

// Preparar o HTML do histórico
$html = '<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Histórico Escolar - ' . htmlspecialchars($aluno['nome'] ?? '') . '</title>
    <style>
        @page {
            margin: 2cm;
            footer: html_footer;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            margin: 0;
            padding: 0;
            background: white;
            font-size: 11px;
        }
        .historico-container {
            max-width: 100%;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 15px;
        }
        .logo-escola {
            max-height: 80px;
            max-width: 150px;
            margin-bottom: 10px;
        }
        .nome-escola {
            font-size: 18px;
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
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin: 10px 0 5px 0;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .secao {
            margin-top: 20px;
            margin-bottom: 15px;
        }
        .secao-titulo {
            font-size: 13px;
            font-weight: bold;
            background-color: #006B3E;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .info-aluno {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .info-aluno td {
            padding: 6px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        .info-aluno td:first-child {
            width: 30%;
            font-weight: bold;
            background-color: #f5f5f5;
        }
        .foto-container {
            text-align: center;
            vertical-align: middle;
        }
        .foto-aluno {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #006B3E;
        }
        .sem-foto {
            width: 100px;
            height: 100px;
            background-color: #f0f0f0;
            border-radius: 10px;
            border: 2px solid #006B3E;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: #999;
        }
        .tabela-notas {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10px;
        }
        .tabela-notas th {
            background-color: #006B3E;
            color: white;
            padding: 6px;
            text-align: center;
            border: 1px solid #ddd;
        }
        .tabela-notas td {
            padding: 5px;
            border: 1px solid #ddd;
            text-align: center;
        }
        .tabela-notas td:first-child {
            text-align: left;
        }
        .ano-card {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .ano-titulo {
            font-size: 12px;
            font-weight: bold;
            color: #006B3E;
            background-color: #e8f5e9;
            padding: 6px;
            border-left: 4px solid #006B3E;
            margin-bottom: 10px;
        }
        .media-geral {
            text-align: right;
            font-weight: bold;
            margin-top: 10px;
            padding-top: 5px;
            border-top: 1px solid #ddd;
        }
        .assinatura {
            margin-top: 40px;
            page-break-inside: avoid;
        }
        .assinatura-linha {
            width: 200px;
            border-top: 1px solid #000;
            margin: 0 auto 10px auto;
        }
        .badge-aprovado { color: #28a745; font-weight: bold; }
        .badge-reprovado { color: #dc3545; font-weight: bold; }
        .badge-recuperacao { color: #ffc107; font-weight: bold; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9px;
            color: #555;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            background: white;
        }
        .page-number {
            text-align: center;
            font-size: 9px;
            color: #999;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="historico-container">';

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
            <div class="titulo-principal">HISTÓRICO ESCOLAR</div>
        </div>';

// Dados Pessoais do Aluno com Foto
$html .= '
        <div class="secao">
            <div class="secao-titulo">DADOS PESSOAIS</div>
            <table class="info-aluno">
                <tr>
                    <td colspan="2" class="foto-container" style="text-align: center;">';
if (!empty($foto_base64)) {
    $html .= '<img src="' . $foto_base64 . '" class="foto-aluno">';
} else {
    $html .= '<div class="sem-foto">SEM FOTO</div>';
}
$html .= '
                    </td>
                </tr>
                <tr>
                    <td width="30%">Nome Completo</td>
                    <td width="70%"><strong>' . strtoupper(htmlspecialchars($aluno['nome'] ?? '')) . '</strong></td>
                </tr>
                <tr>
                    <td>Data de Nascimento</td>
                    <td>' . formatarDataExtenso($aluno['data_nascimento'] ?? '') . '</td>
                </tr>
                <tr>
                    <td>BI / Documento</td>
                    <td>' . htmlspecialchars($aluno['bi'] ?? '_______________') . '</td>
                </tr>
                <tr>
                    <td>Filiação</td>
                    <td>' . htmlspecialchars($aluno['pai_nome'] ?? '') . ' / ' . htmlspecialchars($aluno['mae_nome'] ?? '') . '</td>
                </tr>
                <tr>
                    <td>Nacionalidade</td>
                    <td>' . htmlspecialchars($aluno['pais_nome'] ?? 'Angolana') . '</td>
                </tr>
                <tr>
                    <td>Naturalidade</td>
                    <td>' . htmlspecialchars($aluno['cidade_nome'] ?? '') . ' - ' . htmlspecialchars($aluno['provincia_nome'] ?? '') . '</td>
                </tr>
                <tr>
                    <td>Endereço</td>
                    <td>' . htmlspecialchars($aluno['endereco'] ?? '') . '</td>
                </tr>
                <tr>
                    <td>Contacto</td>
                    <td>Tel: ' . htmlspecialchars($aluno['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($aluno['email'] ?? '') . '</td>
                </tr>
            </table>
        </div>';

// Histórico de Matrículas
if (!empty($matriculas)) {
    $html .= '
        <div class="secao">
            <div class="secao-titulo">HISTÓRICO DE MATRÍCULAS</div>
            <table class="tabela-notas">
                <thead>
                    <tr>
                        <th>Ano Letivo</th>
                        <th>Nº Matrícula</th>
                        <th>Turma</th>
                        <th>Classe</th>
                        <th>Curso</th>
                        <th>Turno</th>
                        <th>Data Matrícula</th>
                    </tr>
                </thead>
                <tbody>';
    foreach ($matriculas as $mat) {
        $html .= '
                    <tr>
                        <td>' . htmlspecialchars($mat['ano_letivo'] ?? '') . '</td>
                        <td>' . htmlspecialchars($mat['numero_processo'] ?? '') . '</td>
                        <td>' . htmlspecialchars($mat['turma_nome'] ?? '-') . '</td>
                        <td>' . htmlspecialchars($mat['classe'] ?? '-') . '</td>
                        <td>' . htmlspecialchars($mat['curso'] ?? '-') . '</td>
                        <td>' . htmlspecialchars($mat['turno'] ?? '-') . '</td>
                        <td>' . formatarData($mat['data_matricula'] ?? '') . '</td>
                    </tr>';
    }
    $html .= '
                </tbody>
            </table>
        </div>';
}

// Histórico de Notas por Ano Letivo
if (!empty($notas_por_ano)) {
    $html .= '
        <div class="secao">
            <div class="secao-titulo">HISTÓRICO DE NOTAS</div>';
    
    foreach ($notas_por_ano as $ano => $notas_ano) {
        $media_geral = getMediaGeralPorAno($notas, $ano);
        $situacao = getSituacaoPorMedia($media_geral);
        
        $html .= '
            <div class="ano-card">
                <div class="ano-titulo">
                    ANO LETIVO: ' . $ano . ' | Média Geral: ' . number_format($media_geral, 1) . ' valores | ' . $situacao['texto'] . '
                </div>
                <table class="tabela-notas">
                    <thead>
                        <tr>
                            <th width="25%">Disciplina</th>
                            <th width="10%">MAC</th>
                            <th width="10%">NPT</th>
                            <th width="10%">Exame Normal</th>
                            <th width="10%">Exame Recurso</th>
                            <th width="10%">Média Final</th>
                            <th width="15%">Classificação</th>
                            <th width="10%">Status</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($notas_ano as $nota) {
            $classificacao = getNotaPorExtenso($nota['media_final'] ?? 0);
            $status_class = $nota['nota_status'] == 'aprovado' ? 'badge-aprovado' : ($nota['nota_status'] == 'recuperacao' ? 'badge-recuperacao' : 'badge-reprovado');
            
            $html .= '
                        <tr>
                            <td style="text-align:left">' . htmlspecialchars($nota['disciplina_nome'] ?? '') . '</td>
                            <td>' . number_format($nota['mac'] ?? 0, 1) . '</td>
                            <td>' . number_format($nota['npt'] ?? 0, 1) . '</td>
                            <td>' . number_format($nota['exame_normal'] ?? 0, 1) . '</td>
                            <td>' . number_format($nota['exame_recurso'] ?? 0, 1) . '</td>
                            <td><strong>' . number_format($nota['media_final'] ?? 0, 1) . '</strong></td>
                            <td>' . $classificacao . '</td>
                            <td class="' . $status_class . '">' . ucfirst($nota['nota_status'] ?? 'Pendente') . '</td>
                        </tr>';
        }
        
        $html .= '
                    </tbody>
                </table>
                <div class="media-geral">
                    Média Geral do Ano: <strong>' . number_format($media_geral, 1) . ' valores</strong> - ' . $situacao['texto'] . '
                </div>
            </div>';
    }
    
    $html .= '
        </div>';
}

// Informações Complementares
$html .= '
        <div class="secao">
            <div class="secao-titulo">INFORMAÇÕES COMPLEMENTARES</div>
            <table class="info-aluno">
                <tr>
                    <td width="30%">Encarregado de Educação</td>
                    <td width="70%">' . htmlspecialchars($aluno['encarregado_nome'] ?? 'Não informado') . ' (' . htmlspecialchars($aluno['encarregado_parentesco'] ?? '') . ')</td>
                </tr>
                <tr>
                    <td>Contacto do Encarregado</td>
                    <td>Tel: ' . htmlspecialchars($aluno['encarregado_telefone'] ?? '') . ' | Email: ' . htmlspecialchars($aluno['encarregado_email'] ?? '') . '</td>
                </tr>
                <tr>
                    <td>Data de Emissão</td>
                    <td>' . formatarDataExtenso(date('Y-m-d')) . '</td>
                </tr>
            </table>
        </div>';

// Assinaturas - uma ao lado da outra
$html .= '
        <div class="assinatura">
            <div style="display: flex; justify-content: space-between; margin-top: 40px;">
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

$html .= '
    </div>';

// Rodapé fixo em todas as páginas
$html .= '
    <div class="footer">
        <div>' . htmlspecialchars($aluno['escola_endereco'] ?? '') . '</div>
        <div style="margin-top: 3px;">
            Documento emitido eletronicamente nos termos da legislação em vigor.
        </div>
        <div style="margin-top: 3px;">
            ' . $escola_nome_rodape . ' - ' . $escola_numero . ' - Histórico Escolar emitido em ' . formatarDataExtenso(date('Y-m-d')) . '
        </div>
    </div>
    
</body>
</html>';

// Gerar PDF
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Adicionar numeração de páginas
$dompdf->getCanvas()->page_text(280, 800, "Página {PAGE_NUM} de {PAGE_COUNT}", null, 9, array(0, 0, 0));

// Nome do arquivo
$nome_arquivo = 'historico_escolar_' . $aluno['matricula'] . '_' . date('Ymd') . '.pdf';

// Enviar para download
$dompdf->stream($nome_arquivo, array('Attachment' => true));
exit;
?>