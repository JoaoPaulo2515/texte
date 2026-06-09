<?php
// escola/servicos_pedagogicos/gerais/gerar_lista_alunos_pdf.php

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    die('Não autorizado');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

if ($turma_id <= 0) {
    die('ID da turma inválido');
}

// Buscar dados da turma
$stmt_turma = $conn->prepare("SELECT * FROM turmas WHERE id = :id AND escola_id = :escola_id");
$stmt_turma->execute([':id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada');
}

// Buscar alunos da turma
$stmt_alunos = $conn->prepare("
    SELECT e.id, e.nome, e.matricula, e.data_nascimento, e.genero,
           m.numero_processo, m.data_matricula
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
");
$stmt_alunos->execute([':turma_id' => $turma_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar dados da escola
$stmt_escola = $conn->prepare("SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id");
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

function formatarData($data) {
    if (empty($data)) return '__ / __ / ____';
    return date('d / m / Y', strtotime($data));
}

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

$html = '<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Lista Nominal dos Estudantes</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; margin: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .logo { max-height: 60px; }
        .titulo { font-size: 14px; font-weight: bold; margin: 10px 0; text-transform: uppercase; }
        .info-turma { text-align: center; font-size: 12px; margin-bottom: 20px; }
        .info-turma span { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #006B3E; color: white; padding: 8px; text-align: center; border: 1px solid #ddd; }
        td { padding: 6px; border: 1px solid #ddd; text-align: center; }
        td:first-child, td:nth-child(2), td:nth-child(3) { text-align: left; }
        .total { text-align: right; margin-top: 20px; font-weight: bold; }
        .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #666; border-top: 1px solid #ccc; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="titulo">LISTA NOMINAL DOS ESTUDANTES</div>
    </div>
    <div class="info-turma">
        Classe: <span>' . $turma['ano'] . 'ª Classe</span> | 
        Sala: <span>' . ($turma['sala'] ?: 'Não definida') . '</span> | 
        Turma: <span>' . $turma['nome'] . '</span> | 
        Período: <span>' . ucfirst($turma['turno']) . '</span> | 
        Ano Lectivo: <span>' . $turma['ano_letivo'] . '</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Nº</th>
                <th>Nº Processo</th>
                <th>Nome do estudante</th>
                <th>Data</th>
                <th>Género</th>
            </tr>
        </thead>
        <tbody>';

$i = 1;
foreach ($alunos as $aluno) {
    $html .= '<tr>
        <td>' . $i++ . '</td>
        <td>' . ($aluno['numero_processo'] ?: $aluno['matricula']) . '</td>
        <td>' . htmlspecialchars($aluno['nome']) . '</td>
        <td>' . formatarData($aluno['data_nascimento']) . '</td>
        <td>' . ($aluno['genero'] == 'M' ? 'Masculino' : 'Feminino') . '</td>
    </tr>';
}

$html .= '
        </tbody>
    </table>
    <div class="total">Total: ' . count($alunos) . '</div>
    <div class="footer">
        Documento emitido eletronicamente - ' . date('d/m/Y H:i') . '
    </div>
</body>
</html>';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('lista_nominal_turma_' . $turma['nome'] . '_' . date('Ymd') . '.pdf', array('Attachment' => true));
exit;
?>