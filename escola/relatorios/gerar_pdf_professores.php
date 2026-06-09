<?php
// escola/relatorios/gerar_pdf_professores.php - Gerar PDF do Relatório de Professores

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}



$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// RECEBER FILTROS
// ============================================
$status_filtro = $_GET['status'] ?? 'todos';
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$search = $_GET['search'] ?? '';

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nuit FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR PROFESSORES COM FILTROS
// ============================================
$sql_professores = "SELECT p.*, 
                    COUNT(DISTINCT pdt.turma_id) as total_turmas,
                    COUNT(DISTINCT pdt.disciplina_id) as total_disciplinas,
                    GROUP_CONCAT(DISTINCT d.nome SEPARATOR ', ') as disciplinas_nomes
                    FROM funcionarios p
                    LEFT JOIN professor_disciplina_turma pdt ON pdt.professor_id = p.id
                    LEFT JOIN disciplinas d ON d.id = pdt.disciplina_id
                    WHERE p.escola_id = :escola_id  and p.tipo_funcionario='professor'";

$params = [':escola_id' => $escola_id];

if ($status_filtro != 'todos') {
    $sql_professores .= " AND p.status = :status";
    $params[':status'] = $status_filtro;
}

if ($disciplina_id > 0) {
    $sql_professores .= " AND pdt.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_id;
}

if (!empty($search)) {
    $sql_professores .= " AND (p.nome LIKE :search_nome 
                            OR p.email LIKE :search_email 
                            OR p.telefone LIKE :search_telefone 
                            OR p.bi LIKE :search_bi)";
    $search_value = "%$search%";
    $params[':search_nome'] = $search_value;
    $params[':search_email'] = $search_value;
    $params[':search_telefone'] = $search_value;
    $params[':search_bi'] = $search_value;
}

$sql_professores .= " GROUP BY p.id ORDER BY p.nome";

$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute($params);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALCULAR ESTATÍSTICAS
// ============================================
$estatisticas = [
    'total' => 0,
    'ativos' => 0,
    'inativos' => 0,
    'total_turmas' => 0
];

foreach ($professores as $professor) {
    $estatisticas['total']++;
    if ($professor['status'] == 'ativo') {
        $estatisticas['ativos']++;
    } else {
        $estatisticas['inativos']++;
    }
    $estatisticas['total_turmas'] += $professor['total_turmas'];
}

// ============================================
// GERAR HTML PARA PDF
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Professores</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
            font-size: 12px;
            padding: 20px;
            background: white;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 15px;
        }
        .header h1 {
            font-size: 22px;
            color: #006B3E;
            margin-bottom: 5px;
        }
        .header h2 {
            font-size: 16px;
            font-weight: normal;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 11px;
            color: #666;
        }
        .info-relatorio {
            background: #f5f5f5;
            padding: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #006B3E;
            font-size: 11px;
        }
        .info-relatorio strong {
            color: #006B3E;
        }
        .estatisticas {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .card-estatistica {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 15px;
            min-width: 120px;
            text-align: center;
        }
        .card-estatistica .numero {
            font-size: 24px;
            font-weight: bold;
            color: #006B3E;
        }
        .card-estatistica .label {
            font-size: 11px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #006B3E;
            color: white;
            font-weight: bold;
            text-align: center;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: bold;
        }
        .status-ativo {
            background: #d4edda;
            color: #155724;
        }
        .status-inativo {
            background: #f8d7da;
            color: #721c24;
        }
        .disciplina-badge {
            display: inline-block;
            background: #17a2b8;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 8px;
            margin: 1px;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>' . htmlspecialchars($escola_info['nome']) . '</h1>
        <h2>Relatório de Professores</h2>
        <p>Gerado em: ' . date('d/m/Y H:i:s') . '</p>
    </div>
    
    <div class="info-relatorio">
        <strong>Filtros aplicados:</strong><br>';
        
        if ($status_filtro != 'todos') {
            $html .= 'Status: ' . ($status_filtro == 'ativo' ? 'Ativos' : 'Inativos') . '<br>';
        }
        if ($disciplina_id > 0) {
            // Buscar nome da disciplina
            $sql_disc = "SELECT nome FROM disciplinas WHERE id = :id";
            $stmt_disc = $conn->prepare($sql_disc);
            $stmt_disc->execute([':id' => $disciplina_id]);
            $disciplina_nome = $stmt_disc->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            $html .= 'Disciplina: ' . htmlspecialchars($disciplina_nome) . '<br>';
        }
        if (!empty($search)) {
            $html .= 'Pesquisa: ' . htmlspecialchars($search) . '<br>';
        }
        if ($status_filtro == 'todos' && $disciplina_id == 0 && empty($search)) {
            $html .= 'Nenhum filtro aplicado (todos os professores)<br>';
        }
        
$html .= '
    </div>
    
    <div class="estatisticas">
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas['total'] . '</div>
            <div class="label">Total Professores</div>
        </div>
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas['ativos'] . '</div>
            <div class="label">Ativos</div>
        </div>
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas['inativos'] . '</div>
            <div class="label">Inativos</div>
        </div>
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas['total_turmas'] . '</div>
            <div class="label">Total Turmas</div>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="20%">Nome</th>
                <th width="10%">BI</th>
                <th width="15%">Email</th>
                <th width="10%">Telefone</th>
                <th width="20%">Disciplinas</th>
                <th width="8%">Turmas</th>
                <th width="12%">Status</th>
            </tr>
        </thead>
        <tbody>';

if (empty($professores)) {
    $html .= '<tr><td colspan="8" class="text-center" style="text-align: center;">Nenhum professor encontrado com os filtros selecionados.</td></tr>';
} else {
    foreach ($professores as $index => $professor) {
        $html .= '<tr>
            <td class="text-center">' . ($index + 1) . '</td>
            <td><strong>' . htmlspecialchars($professor['nome']) . '</strong></td>
            <td>' . htmlspecialchars($professor['bi'] ?: '---') . '</td>
            <td>' . htmlspecialchars($professor['email'] ?: '---') . '</td>
            <td>' . htmlspecialchars($professor['telefone'] ?: '---') . '</td>
            <td>';
        
        if (!empty($professor['disciplinas_nomes'])) {
            $disciplinas_array = explode(', ', $professor['disciplinas_nomes']);
            foreach ($disciplinas_array as $disc) {
                if (trim($disc)) {
                    $html .= '<span class="disciplina-badge">' . htmlspecialchars($disc) . '</span> ';
                }
            }
        } else {
            $html .= '---';
        }
        
        $html .= '</td>
            <td class="text-center">' . $professor['total_turmas'] . '</td>
            <td class="text-center">
                <span class="status-badge status-' . $professor['status'] . '">' . ucfirst($professor['status']) . '</span>
            </td>
        </tr>';
    }
}

$html .= '
        </tbody>
     </table>
    
    <div class="footer">
        <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
        <p>' . htmlspecialchars($escola_info['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola_info['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola_info['email'] ?? '') . '</p>
        <p>Total de registros: ' . count($professores) . ' professores</p>
    </div>
</body>
</html>';

// ============================================
// GERAR PDF
// ============================================
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nome do arquivo
$filename = 'relatorio_professores';
if ($status_filtro != 'todos') {
    $filename .= '_' . $status_filtro;
}
if ($disciplina_id > 0) {
    $filename .= '_disciplina_' . $disciplina_id;
}
if (!empty($search)) {
    $filename .= '_pesquisa';
}
$filename .= '_' . date('Y-m-d') . '.pdf';

$dompdf->stream($filename, ["Attachment" => true]);
exit;
?>