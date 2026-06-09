<?php
// escola/professor/gerar_pdf_horario.php - Gerar PDF do Horário

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Buscar dados do professor
$sql_professor = "SELECT nome FROM funcionarios WHERE id = :id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':id' => $professor_id]);
$professor_dados = $stmt_professor->fetch(PDO::FETCH_ASSOC);

// Buscar horários
$sql_horario = "
    SELECT 
        pdt.dia_semana,
        pdt.horario_inicio,
        pdt.horario_fim,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.sala,
        d.nome as disciplina_nome
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY FIELD(pdt.dia_semana, 'SEGUNDA', 'TERCA', 'QUARTA', 'QUINTA', 'SEXTA', 'SABADO'), pdt.horario_inicio
";

$stmt_horario = $conn->prepare($sql_horario);
$stmt_horario->execute([':professor_id' => $professor_id]);
$horarios = $stmt_horario->fetchAll(PDO::FETCH_ASSOC);

$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Meu Horário - ' . htmlspecialchars($professor_dados['nome'] ?? 'Professor') . '</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .titulo { color: #006B3E; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #006B3E; color: white; padding: 10px; text-align: center; }
        td { border: 1px solid #ddd; padding: 8px; text-align: center; }
        .footer { text-align: center; margin-top: 30px; font-size: 9pt; color: #666; }
        .aula-item { margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h2 class="titulo">MEU HORÁRIO DE AULAS</h2>
        <p>Professor: ' . htmlspecialchars($professor_dados['nome'] ?? '') . '</p>
        <p>Data de Emissão: ' . date('d/m/Y H:i:s') . '</p>
    </div>
    <table>
        <thead>
            <tr><th>Dia</th><th>Horário</th><th>Disciplina</th><th>Turma</th><th>Sala</th></tr>
        </thead>
        <tbody>';

foreach ($horarios as $horario) {
    $dia = '';
    switch($horario['dia_semana']) {
        case 'SEGUNDA': $dia = 'Segunda-feira'; break;
        case 'TERCA': $dia = 'Terça-feira'; break;
        case 'QUARTA': $dia = 'Quarta-feira'; break;
        case 'QUINTA': $dia = 'Quinta-feira'; break;
        case 'SEXTA': $dia = 'Sexta-feira'; break;
        case 'SABADO': $dia = 'Sábado'; break;
        default: $dia = '-';
    }
    $html .= '<tr><td>' . $dia . '</td><td>' . date('H:i', strtotime($horario['horario_inicio'])) . ' - ' . date('H:i', strtotime($horario['horario_fim'])) . '</td><td>' . htmlspecialchars($horario['disciplina_nome']) . '</td><td>' . $horario['turma_ano'] . 'ª ' . htmlspecialchars($horario['turma_nome']) . '</td><td>' . ($horario['sala'] ?: '-') . '</td></tr>';
}

$html .= '</tbody></table><div class="footer">SIGE Angola - Sistema Integrado de Gestão Escolar<br>Documento emitido eletronicamente</div></body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

if (isset($_POST['gerar_pdf'])) {
    $dompdf->stream('meu_horario.pdf', ['Attachment' => false]);
} else {
    $dompdf->stream('meu_horario.pdf', ['Attachment' => true]);
}
exit;
?>