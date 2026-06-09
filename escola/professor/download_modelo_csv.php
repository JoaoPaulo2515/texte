<?php
// escola/professor/download_modelo_csv.php

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=modelo_questoes.csv');

$output = fopen('php://output', 'w');

// Cabeçalho
fputcsv($output, [
    'Enunciado', 'Tipo', 'Pontuação', 
    'Alternativa A', 'Alternativa B', 'Alternativa C', 'Alternativa D', 'Alternativa E',
    'Alternativa Correta (0-4)', 'Dica', 'URL da Imagem', 'URL do Vídeo'
], ';');

// Exemplo 1: Múltipla Escolha
fputcsv($output, [
    'Qual é a capital de Angola?', 'multipla_escolha', '2.00',
    'Luanda', 'Benguela', 'Huambo', 'Lubango', 'Namibe',
    '0', 'A capital está localizada no litoral', '', ''
], ';');

// Exemplo 2: Verdadeiro/Falso
fputcsv($output, [
    'A cidade de Luanda é a capital de Angola.', 'verdadeiro_falso', '1.00',
    '', '', '', '', '',
    '0', 'Pergunta básica sobre geografia de Angola', '', ''
], ';');

// Exemplo 3: Dissertativa
fputcsv($output, [
    'Explique a importância da independência de Angola.', 'dissertativa', '5.00',
    '', '', '', '', '',
    '', 'Resposta deve abordar aspectos históricos e culturais', '', ''
], ';');

fclose($output);
?>