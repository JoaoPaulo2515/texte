<?php
// escola/secretaria/gerar_pdf_certificado.php - Gerar PDF do Certificado

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
$tipo_certificado = isset($_GET['tipo']) ? $_GET['tipo'] : 'declaracao';

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
        e.mae_nome,
        e.encarregado_nome,
        e.encarregado_parentesco,
        e.ano_letivo,
        e.ano_escolar,
        e.curso,
        e.nivel,
        e.classe,
        e.created_at as data_cadastro,
        m.id as matricula_id,
        m.turma_id,
        m.turno,
        m.sala,
        m.classe as matricula_classe,
        m.curso as matricula_curso,
        m.nivel as matricula_nivel,
        m.ano_letivo as matricula_ano,
        m.numero_processo,
        m.status as matricula_status,
        m.data_matricula,
        t.nome as turma_nome,
        t.ano as turma_ano,
        es.nome as escola_nome,
        es.nome as escola_razao,
        es.logo as escola_logo,
        es.endereco as escola_endereco,
        es.telefone as escola_telefone,
        es.email as escola_email,
        es.nuit as escola_nuit
    FROM estudantes e
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id
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
// BUSCAR NOTAS DO ALUNO
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
        d.nome as disciplina_nome
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    WHERE n.estudante_id = :estudante_id 
    AND n.ano_letivo_id = (SELECT id FROM ano_letivo WHERE ativo = 1 LIMIT 1)
    ORDER BY n.bimestre, d.nome
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

function getSituacaoAluno($media_final) {
    if ($media_final >= 10) {
        return ['texto' => 'Aprovado', 'classe' => 'aprovado'];
    } elseif ($media_final >= 7) {
        return ['texto' => 'Recuperação', 'classe' => 'recuperacao'];
    } else {
        return ['texto' => 'Reprovado', 'classe' => 'reprovado'];
    }
}

function getMediaGeral($notas) {
    if (empty($notas)) return 0;
    $soma = 0;
    foreach ($notas as $nota) {
        $soma += $nota['media_final'] ?? 0;
    }
    return round($soma / count($notas), 1);
}

// Função para converter texto para UTF-8 para o PDF
function toUtf8($texto) {
    return mb_convert_encoding($texto, 'UTF-8', 'auto');
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

// Preparar o HTML do certificado
$html = '<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Certificado</title>
    <style>
        body {
            font-family: "DejaVu Sans", sans-serif;
            margin: 0;
            padding: 0;
            background: white;
        }
        .certificado-container {
            padding: 40px;
            min-height: 100vh;
        }
        .certificado-bordas {
            border: 2px solid #006B3E;
            padding: 30px;
            position: relative;
        }
        .certificado-bordas:before {
            content: "";
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            bottom: 10px;
            border: 1px solid #006B3E;
            pointer-events: none;
        }
        .certificado-titulo {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .certificado-subtitulo {
            text-align: center;
            font-size: 14px;
            color: #666;
            margin-bottom: 30px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .certificado-corpo {
            font-size: 12px;
            line-height: 1.8;
            text-align: justify;
        }
        .certificado-assinatura {
            margin-top: 40px;
            text-align: center;
        }
        .certificado-assinatura-linha {
            width: 200px;
            border-top: 1px solid #000;
            margin: 0 auto 10px auto;
        }
        .foto-aluno-certificado {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #006B3E;
            margin-bottom: 15px;
        }
        .text-center { text-align: center; }
        .mb-3 { margin-bottom: 15px; }
        .mt-3 { margin-top: 15px; }
        .mt-4 { margin-top: 20px; }
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .table th, .table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .nota-aprovado { color: #28a745; font-weight: bold; }
        .nota-reprovado { color: #dc3545; font-weight: bold; }
        .nota-recuperacao { color: #ffc107; font-weight: bold; }
        .badge-aprovado { background: #28a745; color: white; padding: 3px 8px; border-radius: 12px; }
        .badge-reprovado { background: #dc3545; color: white; padding: 3px 8px; border-radius: 12px; }
        .badge-recuperacao { background: #ffc107; color: #333; padding: 3px 8px; border-radius: 12px; }
        .logo-escola {
            max-height: 80px;
            max-width: 150px;
        }
    </style>
</head>
<body>
    <div class="certificado-container">';

// Gerar conteúdo baseado no tipo de certificado
$situacao = getSituacaoAluno(getMediaGeral($notas));
$badge_class = $situacao['classe'] == 'aprovado' ? 'success' : ($situacao['classe'] == 'recuperacao' ? 'warning' : 'danger');

if ($tipo_certificado == 'declaracao'):
    $html .= '
        <div class="certificado-bordas">
            <div class="text-center mb-3">
                ' . (!empty($aluno['escola_logo']) ? '<img src="../../uploads/escolas/logos/' . $aluno['escola_logo'] . '" class="logo-escola">' : '<i class="fas fa-school fa-3x"></i>') . '
            </div>
            <div class="certificado-titulo">DECLARAÇÃO DE MATRÍCULA</div>
            <div class="certificado-subtitulo">' . htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA') . '</div>
            
            <div class="certificado-corpo">
                <p>Ao presente instrumento declaramos para os devidos fins que o(a) aluno(a) <strong>' . htmlspecialchars($aluno['nome'] ?? '') . '</strong>, 
                portador(a) do Bilhete de Identidade nº <strong>' . htmlspecialchars($aluno['bi'] ?? '_______________') . '</strong>, 
                encontra-se regularmente matriculado(a) nesta instituição de ensino no ano letivo de <strong>' . htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')) . '</strong>, 
                na <strong>' . htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? '') . '</strong> classe, 
                turma <strong>' . htmlspecialchars($aluno['turma_nome'] ?? '') . '</strong>, 
                no turno <strong>' . htmlspecialchars($aluno['turno'] ?? '') . '</strong>.</p>
                
                <p>O presente documento serve para os fins que se fizerem necessários, nomeadamente para comprovação de matrícula.</p>
                
                <p class="mt-4">' . htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA') . ', aos ' . formatarDataExtenso(date('Y-m-d')) . '.</p>
            </div>
            
            <div class="certificado-assinatura">
                <div class="certificado-assinatura-linha"></div>
                <p>Secretaria Académica</p>
                <p><small>Carimbo e Assinatura</small></p>
            </div>
        </div>';

elseif ($tipo_certificado == 'certificado_conclusao'):
    $html .= '
        <div class="certificado-bordas">
            <div class="text-center mb-3">
                ' . (!empty($aluno['escola_logo']) ? '<img src="../../uploads/escolas/logos/' . $aluno['escola_logo'] . '" class="logo-escola">' : '<i class="fas fa-graduation-cap fa-3x"></i>') . '
            </div>
            <div class="certificado-titulo">CERTIFICADO DE CONCLUSÃO</div>
            <div class="certificado-subtitulo">' . htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA') . '</div>
            
            <div class="certificado-corpo">
                <p>Certificamos que <strong>' . htmlspecialchars($aluno['nome'] ?? '') . '</strong>, 
                filho(a) de <strong>' . htmlspecialchars($aluno['pai_nome'] ?? '') . '</strong> e 
                <strong>' . htmlspecialchars($aluno['mae_nome'] ?? '') . '</strong>, 
                nascido(a) aos ' . formatarDataExtenso($aluno['data_nascimento'] ?? '') . ', 
                portador(a) do BI nº <strong>' . htmlspecialchars($aluno['bi'] ?? '_______________') . '</strong>, 
                concluiu com aproveitamento o <strong>' . htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? '') . '</strong> 
                do <strong>' . htmlspecialchars($aluno['nivel'] ?? 'Ensino') . '</strong>, 
                no ano letivo de <strong>' . htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')) . '</strong>.</p>
                
                <p>A média final do aluno foi de <strong>' . getMediaGeral($notas) . ' valores</strong>, 
                tendo sido considerado(a) <strong>' . $situacao['texto'] . '</strong>.</p>
                
                <p>Para constar, emitimos o presente certificado.</p>
                
                <p class="mt-4">' . htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA') . ', aos ' . formatarDataExtenso(date('Y-m-d')) . '.</p>
            </div>
            
            <div class="certificado-assinatura">
                <div class="row" style="display: flex;">
                    <div style="flex: 1; text-align: center;">
                        <div class="certificado-assinatura-linha"></div>
                        <p>Director Pedagógico</p>
                    </div>
                    <div style="flex: 1; text-align: center;">
                        <div class="certificado-assinatura-linha"></div>
                        <p>Secretário</p>
                    </div>
                </div>
            </div>
        </div>';

elseif ($tipo_certificado == 'atestado_frequencia'):
    $html .= '
        <div class="certificado-bordas">
            <div class="text-center mb-3">
                ' . (!empty($aluno['escola_logo']) ? '<img src="../../uploads/escolas/logos/' . $aluno['escola_logo'] . '" class="logo-escola">' : '<i class="fas fa-clipboard-list fa-3x"></i>') . '
            </div>
            <div class="certificado-titulo">ATESTADO DE FREQUÊNCIA</div>
            <div class="certificado-subtitulo">' . htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA') . '</div>
            
            <div class="certificado-corpo">
                <p>Atestamos para os devidos fins que o(a) aluno(a) <strong>' . htmlspecialchars($aluno['nome'] ?? '') . '</strong>, 
                está regularmente matriculado(a) e frequenta com assiduidade o <strong>' . htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? '') . '</strong> 
                desta instituição de ensino, no ano letivo de <strong>' . htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')) . '</strong>, 
                no turno <strong>' . htmlspecialchars($aluno['turno'] ?? '') . '</strong>.</p>
                
                <p>O presente atestado é emitido a pedido do interessado para os fins que se fizerem necessários.</p>
                
                <p class="mt-4">' . htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA') . ', aos ' . formatarDataExtenso(date('Y-m-d')) . '.</p>
            </div>
            
            <div class="certificado-assinatura">
                <div class="certificado-assinatura-linha"></div>
                <p>Secretaria Académica</p>
            </div>
        </div>';

elseif ($tipo_certificado == 'historico_notas'):
    // Montar tabela de notas
    $tabela_notas = '
        <table class="table">
            <thead>
                <tr>
                    <th>Disciplina</th>
                    <th>MAC</th>
                    <th>NPT</th>
                    <th>Exame</th>
                    <th>Média</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';
    
    $disciplinas_unicas = [];
    foreach ($notas as $nota):
        if (!in_array($nota['disciplina_nome'], $disciplinas_unicas)):
            $disciplinas_unicas[] = $nota['disciplina_nome'];
            $situacao_disciplina = getSituacaoAluno($nota['media_final'] ?? 0);
            $classe_nota = $situacao_disciplina['classe'];
            $badge_nota = $classe_nota == 'aprovado' ? 'badge-aprovado' : ($classe_nota == 'recuperacao' ? 'badge-recuperacao' : 'badge-reprovado');
            
            $tabela_notas .= '
                <tr>
                    <td>' . htmlspecialchars($nota['disciplina_nome']) . '</td>
                    <td>' . number_format($nota['mac'] ?? 0, 1) . '</td>
                    <td>' . number_format($nota['npt'] ?? 0, 1) . '</td>
                    <td>' . number_format($nota['exame_normal'] ?? 0, 1) . '</td>
                    <td><strong class="nota-' . $classe_nota . '">' . number_format($nota['media_final'] ?? 0, 1) . '</strong></td>
                    <td><span class="' . $badge_nota . '">' . $situacao_disciplina['texto'] . '</span></td>
                </tr>';
        endif;
    endforeach;
    
    $tabela_notas .= '
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4">Média Geral</th>
                    <th colspan="2"><strong>' . number_format(getMediaGeral($notas), 1) . ' valores</strong></th>
                </tr>
            </tfoot>
         </table>';
    
    $html .= '
        <div class="certificado-bordas">
            <div class="text-center mb-3">
                ' . (!empty($aluno['escola_logo']) ? '<img src="../../uploads/escolas/logos/' . $aluno['escola_logo'] . '" class="logo-escola">' : '<i class="fas fa-chart-line fa-3x"></i>') . '
            </div>
            <div class="certificado-titulo">HISTÓRICO DE NOTAS</div>
            <div class="certificado-subtitulo">' . htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA') . '</div>
            
            <div class="certificado-corpo">
                <p><strong>Aluno(a):</strong> ' . htmlspecialchars($aluno['nome'] ?? '') . '</p>
                <p><strong>Nº de Matrícula:</strong> ' . htmlspecialchars($aluno['matricula'] ?? '') . '</p>
                <p><strong>Classe:</strong> ' . htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? '') . '</p>
                <p><strong>Ano Letivo:</strong> ' . htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')) . '</p>
                
                ' . $tabela_notas . '
                
                <p class="mt-4">' . htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA') . ', aos ' . formatarDataExtenso(date('Y-m-d')) . '.</p>
            </div>
            
            <div class="certificado-assinatura">
                <div class="certificado-assinatura-linha"></div>
                <p>Secretaria Académica</p>
            </div>
        </div>';

else: // transferencia
    $html .= '
        <div class="certificado-bordas">
            <div class="text-center mb-3">
                ' . (!empty($aluno['escola_logo']) ? '<img src="../../uploads/escolas/logos/' . $aluno['escola_logo'] . '" class="logo-escola">' : '<i class="fas fa-exchange-alt fa-3x"></i>') . '
            </div>
            <div class="certificado-titulo">DECLARAÇÃO DE TRANSFERÊNCIA</div>
            <div class="certificado-subtitulo">' . htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA') . '</div>
            
            <div class="certificado-corpo">
                <p>Declaramos que o(a) aluno(a) <strong>' . htmlspecialchars($aluno['nome'] ?? '') . '</strong>, 
                portador(a) do BI nº <strong>' . htmlspecialchars($aluno['bi'] ?? '_______________') . '</strong>, 
                esteve matriculado(a) nesta instituição de ensino no ano letivo de <strong>' . htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')) . '</strong>, 
                na <strong>' . htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? '') . '</strong> classe.</p>
                
                <p>O aluno está autorizado a transferir-se para outra instituição de ensino, não tendo nenhum débito pendente com esta escola.</p>
                
                <p class="mt-4">' . htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA') . ', aos ' . formatarDataExtenso(date('Y-m-d')) . '.</p>
            </div>
            
            <div class="certificado-assinatura">
                <div class="certificado-assinatura-linha"></div>
                <p>Secretaria Académica</p>
                <p><small>Carimbo e Assinatura</small></p>
            </div>
        </div>';

endif;

$html .= '
    </div>
</body>
</html>';

// Gerar PDF
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo
$nome_arquivo = 'certificado_' . $tipo_certificado . '_' . $aluno['matricula'] . '_' . date('Ymd') . '.pdf';

// Enviar para download
$dompdf->stream($nome_arquivo, array('Attachment' => true));
exit;
?>