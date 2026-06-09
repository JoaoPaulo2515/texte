<?php
// escola/professor/provas/exportar_resultados.php - Exportar Resultados

require_once __DIR__ . '/../../../config/database.php';
session_start();
/*
if (!isset($_SESSION['usuario_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}*/

$db = Database::getInstance();
$conn = $db->getConnection();
$usuario_id = $_SESSION['usuario_id'];
$escola_id = $_SESSION['escola_id'];

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$apenas_finalizadas = isset($_GET['finalizadas']) ? (int)$_GET['finalizadas'] : 1;
$busca_aluno = isset($_GET['busca']) ? $_GET['busca'] : '';

// Buscar dados da prova
$sql_prova = "SELECT p.titulo, p.nota_maxima, p.nota_minima_aprovacao, d.nome as disciplina_nome
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              WHERE p.id = :prova_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

// Buscar resultados
$sql_resultados = "SELECT 
                        e.nome as aluno_nome,
                        e.matricula as aluno_matricula,
                        t.tentativa_numero,
                        t.data_entrega,
                        t.tempo_gasto_segundos,
                        t.pontuacao_total,
                        t.porcentagem,
                        t.aprovado,
                        t.status
                    FROM online_provas_tentativas t
                    JOIN estudantes e ON e.id = t.aluno_id
                    WHERE t.prova_id = :prova_id";

if ($apenas_finalizadas == 1) {
    $sql_resultados .= " AND t.status = 'finalizada'";
}
if (!empty($busca_aluno)) {
    $sql_resultados .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca)";
}

$sql_resultados .= " ORDER BY t.pontuacao_total DESC";

$stmt_resultados = $conn->prepare($sql_resultados);
$params = [':prova_id' => $prova_id];
if (!empty($busca_aluno)) {
    $params[':busca'] = "%$busca_aluno%";
}
$stmt_resultados->execute($params);
$resultados = $stmt_resultados->fetchAll(PDO::FETCH_ASSOC);

// Configurar cabeçalho para download CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="resultados_' . $prova_id . '_' . date('Ymd') . '.csv"');

// Criar arquivo CSV
$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

// Cabeçalho
fputcsv($output, ['Resultados da Prova: ' . $prova['titulo']]);
fputcsv($output, ['Disciplina: ' . $prova['disciplina_nome']]);
fputcsv($output, ['Nota Máxima: ' . $prova['nota_maxima']]);
fputcsv($output, ['Nota Mínima: ' . $prova['nota_minima_aprovacao']]);
fputcsv($output, ['Data Exportação: ' . date('d/m/Y H:i:s')]);
fputcsv($output, []);
fputcsv($output, ['Aluno', 'Matrícula', 'Tentativa', 'Data Entrega', 'Tempo (s)', 'Nota', 'Porcentagem', 'Status']);

// Dados
foreach ($resultados as $resultado) {
    fputcsv($output, [
        $resultado['aluno_nome'],
        $resultado['aluno_matricula'],
        $resultado['tentativa_numero'] . 'ª',
        date('d/m/Y H:i', strtotime($resultado['data_entrega'])),
        $resultado['tempo_gasto_segundos'],
        $resultado['pontuacao_total'],
        $resultado['porcentagem'] . '%',
        $resultado['aprovado'] == 1 ? 'Aprovado' : ($resultado['status'] == 'abandonada' ? 'Abandonou' : 'Reprovado')
    ]);
}

fclose($output);
exit;
?>