<?php
// escola/professor/gerar_pdf_chamada.php - Gerar PDF da Chamada do Dia

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// CARREGAR DOMPDF
// ============================================
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ============================================
// PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$data_aula = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');

// ============================================
// VALIDAÇÕES
// ============================================
if ($turma_id <= 0 || $disciplina_id <= 0) {
    die('Parâmetros inválidos');
}

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, logo, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "SELECT t.*, COUNT(DISTINCT m.estudante_id) as total_alunos
              FROM turmas t
              LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
              WHERE t.id = :turma_id AND t.escola_id = :escola_id
              GROUP BY t.id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada');
}

// ============================================
// BUSCAR DISCIPLINA
// ============================================
$sql_disciplina = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

if (!$disciplina) {
    die('Disciplina não encontrada');
}

// ============================================
// BUSCAR ALUNOS E CHAMADA DO DIA
// ============================================
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.foto,
        c.status,
        c.observacao,
        c.created_at as data_registro
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN chamada c ON c.estudante_id = e.id 
        AND c.turma_id = :turma_id 
        AND c.disciplina_id = :disciplina_id 
        AND c.data_aula = :data_aula
        AND c.professor_id = :professor_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";

$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':data_aula' => $data_aula,
    ':professor_id' => $professor_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALCULAR ESTATÍSTICAS
// ============================================
$total_alunos = count($alunos);
$total_presentes = 0;
$total_faltas = 0;
$total_atrasos = 0;
$total_justificados = 0;
$total_sem_registro = 0;

foreach ($alunos as $aluno) {
    $status = $aluno['status'] ?? null;
    if ($status == 'presente') {
        $total_presentes++;
    } elseif ($status == 'falta') {
        $total_faltas++;
    } elseif ($status == 'atraso') {
        $total_atrasos++;
    } elseif ($status == 'justificado') {
        $total_justificados++;
    } else {
        $total_sem_registro++;
    }
}

$percentual_presenca = $total_alunos > 0 ? round(($total_presentes / $total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusTexto($status) {
    if (empty($status)) return 'Não registrado';
    switch ($status) {
        case 'presente': return 'Presente';
        case 'falta': return 'Falta';
        case 'atraso': return 'Atraso';
        case 'justificado': return 'Justificado';
        default: return 'Não registrado';
    }
}

function getStatusClass($status) {
    if (empty($status)) return 'badge-secondary';
    switch ($status) {
        case 'presente': return 'badge-presente';
        case 'falta': return 'badge-falta';
        case 'atraso': return 'badge-atraso';
        case 'justificado': return 'badge-justificado';
        default: return 'badge-secondary';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataHora($data) {
    if (empty($data)) return '-';
    return date('d/m/Y H:i:s', strtotime($data));
}

// ============================================
// GERAR HTML PARA PDF
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Chamada - ' . formatarData($data_aula) . ' - ' . htmlspecialchars($disciplina['nome']) . '</title>
    <style>
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10px;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 10px;
        }
        .header h1 {
            color: #006B3E;
            margin: 0;
            font-size: 18px;
        }
        .header h2 {
            margin: 5px 0;
            font-size: 14px;
        }
        .header p {
            margin: 3px 0;
            font-size: 9px;
            color: #666;
        }
        .info-section {
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .info-section table {
            width: 100%;
            font-size: 9px;
        }
        .info-section td {
            padding: 3px;
        }
        .stats-section {
            margin-bottom: 15px;
            overflow: hidden;
        }
        .stats-box {
            float: left;
            width: 23%;
            margin: 0 1%;
            padding: 8px;
            background: #f8f9fa;
            text-align: center;
            border-radius: 5px;
        }
        .stats-number {
            font-size: 18px;
            font-weight: bold;
            color: #006B3E;
        }
        .stats-label {
            font-size: 8px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        th {
            background: #006B3E;
            color: white;
            padding: 8px;
            text-align: center;
            font-size: 9px;
        }
        td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: center;
            font-size: 9px;
        }
        .text-left {
            text-align: left;
        }
        .text-success {
            color: #28a745;
        }
        .text-danger {
            color: #dc3545;
        }
        .text-warning {
            color: #ffc107;
        }
        .text-info {
            color: #17a2b8;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            font-size: 8px;
            color: #666;
        }
        .badge-presente {
            background: #d4edda;
            color: #155724;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        .badge-falta {
            background: #f8d7da;
            color: #721c24;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        .badge-atraso {
            background: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        .badge-justificado {
            background: #d1ecf1;
            color: #0c5460;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        .badge-secondary {
            background: #e9ecef;
            color: #6c757d;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        .assinatura {
            margin-top: 30px;
            text-align: center;
        }
        .assinatura-line {
            display: inline-block;
            width: 200px;
            border-top: 1px solid #000;
            margin-top: 30px;
        }
        .assinatura-text {
            font-size: 9px;
            margin-top: 5px;
        }
    </style>
</head>
<body>

    <!-- Cabeçalho -->
    <div class="header">
        <h1>' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')) . '</h1>
        <h2>PAUTA DE CHAMADA</h2>
        <p>' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</p>
    </div>

    <!-- Informações da Turma -->
    <div class="info-section">
        <table>
            <tr>
                <td width="15%"><strong>Turma:</strong></td>
                <td width="35%">' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</td>
                <td width="15%"><strong>Turno:</strong></td>
                <td width="35%">' . ucfirst($turma['turno']) . '</td>
            </tr>
            <tr>
                <td><strong>Disciplina:</strong></td>
                <td>' . htmlspecialchars($disciplina['nome']) . '</td>
                <td><strong>Sala:</strong></td>
                <td>' . ($turma['sala'] ?? 'Não definida') . '</td>
            </tr>
            <tr>
                <td><strong>Professor:</strong></td>
                <td>' . htmlspecialchars($professor['professor_nome']) . '</td>
                <td><strong>Data:</strong></td>
                <td>' . formatarData($data_aula) . '</td>
            </tr>
            <tr>
                <td><strong>Ano Letivo:</strong></td>
                <td>' . $ano_letivo_ano . '</td>
                <td><strong>Data Emissão:</strong></td>
                <td>' . date('d/m/Y H:i:s') . '</td>
            </tr>
        </table>
    </div>

    <!-- Estatísticas -->
    <div class="stats-section">
        <div class="stats-box">
            <div class="stats-number">' . $total_alunos . '</div>
            <div class="stats-label">Total Alunos</div>
        </div>
        <div class="stats-box">
            <div class="stats-number text-success">' . $total_presentes . '</div>
            <div class="stats-label">Presentes</div>
        </div>
        <div class="stats-box">
            <div class="stats-number text-danger">' . $total_faltas . '</div>
            <div class="stats-label">Faltas</div>
        </div>
        <div class="stats-box">
            <div class="stats-number">' . $percentual_presenca . '%</div>
            <div class="stats-label">Frequência</div>
        </div>
    </div>

    <div class="stats-section">
        <div class="stats-box">
            <div class="stats-number text-warning">' . $total_atrasos . '</div>
            <div class="stats-label">Atrasos</div>
        </div>
        <div class="stats-box">
            <div class="stats-number text-info">' . $total_justificados . '</div>
            <div class="stats-label">Justificados</div>
        </div>
        <div class="stats-box">
            <div class="stats-number text-secondary">' . $total_sem_registro . '</div>
            <div class="stats-label">Não Registrados</div>
        </div>
    </div>

    <!-- Tabela de Chamada -->
    <table>
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="35%">Aluno</th>
                <th width="15%">Matrícula</th>
                <th width="20%">Status</th>
                <th width="25%">Observação</th>
            </tr>
        </thead>
        <tbody>
';

foreach ($alunos as $index => $aluno) {
    $status = $aluno['status'] ?? null;
    $status_texto = getStatusTexto($status);
    $status_class = getStatusClass($status);
    $observacao = $aluno['observacao'] ?? '';
    
    $html .= '
            <tr>
                <td class="text-center">' . ($index + 1) . '</td>
                <td class="text-left"><strong>' . htmlspecialchars($aluno['nome']) . '</strong></td>
                <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
                <td class="text-center">
                    <span class="' . $status_class . '">' . $status_texto . '</span>
                </td>
                <td class="text-left">' . htmlspecialchars($observacao ?: '-') . '</td>
            </tr>
    ';
}

$html .= '
        </tbody>
    </table>
';

// Adicionar observações gerais (se houver)
$observacoes_gerais = array_filter(array_column($alunos, 'observacao'));
if (!empty($observacoes_gerais)) {
    $html .= '
    <div class="info-section">
        <strong><i class="fas fa-comment"></i> Observações Gerais:</strong><br>
        <ul>';
    foreach (array_unique($observacoes_gerais) as $obs) {
        if (!empty($obs)) {
            $html .= '<li>' . htmlspecialchars($obs) . '</li>';
        }
    }
    $html .= '
        </ul>
    </div>';
}

// Assinaturas
$html .= '
    <div class="assinatura">
        <table style="width: 100%; margin-top: 30px;">
            <tr>
                <td style="text-align: center; border: none;">
                    <div class="assinatura-line"></div>
                    <div class="assinatura-text">Professor(a) ' . htmlspecialchars($professor['professor_nome']) . '</div>
                </td>
                <td style="text-align: center; border: none;">
                    <div class="assinatura-line"></div>
                    <div class="assinatura-text">Coordenador(a) Pedagógico(a)</div>
                </td>
            </tr>
        </table>
    </div>
';

// Rodapé
$html .= '
    <div class="footer">
        <p>Documento emitido eletronicamente pelo SIGE Angola - Sistema Integrado de Gestão Escolar</p>
        <p>Este documento é válido como comprovante de frequência para o dia ' . formatarData($data_aula) . '</p>
    </div>
</body>
</html>
';

// ============================================
// CONFIGURAR E GERAR PDF
// ============================================
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);

// Configurar chroot para Windows
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $options->set('chroot', 'C:/xampp/htdocs');
}

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo
$nome_arquivo = 'chamada_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . date('Ymd', strtotime($data_aula)) . '.pdf';

// Enviar para download
$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>