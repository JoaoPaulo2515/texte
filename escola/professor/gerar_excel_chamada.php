<?php
// escola/professor/gerar_excel_chamada.php - Gerar Excel da Chamada

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// RECEBER PARÂMETROS
// ============================================
$turma_id = (int)($_GET['turma_id'] ?? 0);
$disciplina_id = (int)($_GET['disciplina_id'] ?? 0);
$data_aula = $_GET['data'] ?? date('Y-m-d');

// ============================================
// VERIFICAR PERMISSÃO
// ============================================
if ($turma_id == 0 || $disciplina_id == 0) {
    die("Parâmetros inválidos.");
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar dados da turma
$sql_turma = "SELECT nome, ano, turno FROM turmas WHERE id = :turma_id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Buscar dados da disciplina
$sql_disciplina = "SELECT nome FROM disciplinas WHERE id = :disciplina_id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':disciplina_id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// Buscar alunos e suas presenças
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        COALESCE(c.status, 'presente') as status,
        COALESCE(c.observacao, '') as observacao
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN chamada c ON c.estudante_id = e.id 
        AND c.turma_id = m.turma_id 
        AND c.disciplina_id = :disciplina_id 
        AND c.data_aula = :data_aula
    WHERE m.turma_id = :turma_id 
    AND m.status = 'ativa'
    ORDER BY e.nome
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':data_aula' => $data_aula
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_alunos = count($alunos);
$presentes = 0;
$faltas = 0;
$atrasos = 0;
$justificados = 0;

foreach ($alunos as $aluno) {
    switch ($aluno['status']) {
        case 'presente': $presentes++; break;
        case 'falta': $faltas++; break;
        case 'atraso': $atrasos++; break;
        case 'justificado': $justificados++; break;
    }
}
$percentual_presenca = $total_alunos > 0 ? round(($presentes / $total_alunos) * 100, 1) : 0;

// ============================================
// GERAR EXCEL
// ============================================

// Nome do arquivo
$nome_arquivo = "chamada_" . date('Ymd_His') . ".xls";

// Headers para download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$nome_arquivo\"");
header("Pragma: no-cache");
header("Expires: 0");

// Criar conteúdo HTML para Excel
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Relatório de Chamada</title>';
echo '<style>';
echo 'th { background-color: #006B3E; color: white; padding: 8px; }';
echo 'td { padding: 6px; border: 1px solid #ddd; }';
echo '.header { background-color: #f0f0f0; font-weight: bold; }';
echo '.presente { background-color: #d4edda; }';
echo '.falta { background-color: #f8d7da; }';
echo '.atraso { background-color: #fff3cd; }';
echo '.justificado { background-color: #d1ecf1; }';
echo '</style>';
echo '</head>';
echo '<body>';

// Cabeçalho da Escola
echo '<div style="text-align: center; margin-bottom: 20px;">';
echo '<h2>' . htmlspecialchars($escola['nome'] ?? 'SIGE Angola') . '</h2>';
echo '<h3>RELATÓRIO DE CHAMADA</h3>';
echo '<p><strong>Data:</strong> ' . date('d/m/Y H:i:s') . '</p>';
echo '</div>';

// Informações da Chamada
echo '<table width="100%" cellpadding="5" cellspacing="0" style="border-collapse: collapse; margin-bottom: 20px;">';
echo '<tr class="header"><td width="30%"><strong>Turma:</strong></td><td>' . htmlspecialchars($turma['nome'] ?? '-') . '</td></tr>';
echo '<tr class="header"><td><strong>Classe:</strong></td><td>' . ($turma['ano'] ?? '-') . 'ª Classe</td></tr>';
echo '<tr class="header"><td><strong>Turno:</strong></td><td>' . ucfirst($turma['turno'] ?? '-') . '</td></tr>';
echo '<tr class="header"><td><strong>Disciplina:</strong></td><td>' . htmlspecialchars($disciplina['nome'] ?? '-') . '</td></tr>';
echo '<tr class="header"><td><strong>Data da Aula:</strong></td><td>' . date('d/m/Y', strtotime($data_aula)) . '</td></tr>';
echo '<tr class="header"><td><strong>Professor:</strong></td><td>' . htmlspecialchars($professor['professor_nome'] ?? '-') . '</td></tr>';
echo '</table>';

// Estatísticas
echo '<table width="100%" cellpadding="5" cellspacing="0" style="border-collapse: collapse; margin-bottom: 20px;">';
echo '<tr style="background-color: #e9ecef;">';
echo '<th>Total de Alunos</th>';
echo '<th>Presentes</th>';
echo '<th>Faltas</th>';
echo '<th>Atrasos</th>';
echo '<th>Justificados</th>';
echo '<th>% Presença</th>';
echo '</tr>';
echo '<tr style="text-align: center;">';
echo '<td><strong>' . $total_alunos . '</strong></td>';
echo '<td><strong style="color: green;">' . $presentes . '</strong></td>';
echo '<td><strong style="color: red;">' . $faltas . '</strong></td>';
echo '<td><strong style="color: orange;">' . $atrasos . '</strong></td>';
echo '<td><strong style="color: blue;">' . $justificados . '</strong></td>';
echo '<td><strong>' . $percentual_presenca . '%</strong></td>';
echo '</tr>';
echo '</table>';

// Tabela de Alunos
echo '<table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse;">';
echo '<thead>';
echo '<tr>';
echo '<th width="5%">#</th>';
echo '<th width="40%">Nome do Aluno</th>';
echo '<th width="15%">Matrícula</th>';
echo '<th width="20%">Status</th>';
echo '<th width="20%">Observação</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

$contador = 1;
foreach ($alunos as $aluno) {
    $status_texto = '';
    $status_class = '';
    
    switch ($aluno['status']) {
        case 'presente':
            $status_texto = 'Presente';
            $status_class = 'presente';
            break;
        case 'falta':
            $status_texto = 'Falta';
            $status_class = 'falta';
            break;
        case 'atraso':
            $status_texto = 'Atraso';
            $status_class = 'atraso';
            break;
        case 'justificado':
            $status_texto = 'Justificado';
            $status_class = 'justificado';
            break;
        default:
            $status_texto = ucfirst($aluno['status']);
    }
    
    echo '<tr class="' . $status_class . '">';
    echo '<td style="text-align: center;">' . $contador++ . '</td>';
    echo '<td>' . htmlspecialchars($aluno['nome']) . '</td>';
    echo '<td style="text-align: center;">' . htmlspecialchars($aluno['matricula']) . '</td>';
    echo '<td style="text-align: center;">' . $status_texto . '</td>';
    echo '<td>' . htmlspecialchars($aluno['observacao']) . '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';

// Rodapé
echo '<div style="margin-top: 30px; text-align: center; font-size: 10px; color: #999;">';
echo '<hr>';
echo '<p>Documento gerado por SIGE Angola em ' . date('d/m/Y H:i:s') . '</p>';
echo '<p>' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</p>';
echo '</div>';

echo '</body>';
echo '</html>';
exit;
?>