<?php
// escola/professor/inserir_dados_biblioteca.php - Script para inserir dados de exemplo na biblioteca

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// INSERIR CATEGORIAS E MATERIAIS
// ============================================

echo "<h2>Inserindo dados na Biblioteca Virtual...</h2>";

// Buscar ID do funcionário
$sql_func = "SELECT f.id FROM funcionarios f WHERE f.usuario_id = (SELECT usuario_id FROM professores WHERE id = :professor_id) AND f.escola_id = :escola_id LIMIT 1";
$stmt_func = $conn->prepare($sql_func);
$stmt_func->execute([
    ':professor_id' => $professor_id,
    ':escola_id' => $escola_id
]);
$funcionario = $stmt_func->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario ? $funcionario['id'] : 0;

// ============================================
// 1. INSERIR MATERIAIS DIDÁTICOS
// ============================================

$materiais = [
    // Livros
    [
        'titulo' => 'Matemática - 1º Ciclo',
        'descricao' => 'Livro completo de Matemática para o 1º Ciclo do Ensino Secundário. Contém exercícios resolvidos e propostos, teoria detalhada e exemplos práticos.',
        'tipo' => 'livro',
        'categoria' => 'Matemática',
        'disciplina_id' => 1,
        'autor' => 'Prof. João Silva',
        'editora' => 'Editora Educação',
        'destaque' => 1
    ],
    [
        'titulo' => 'Português - Gramática Atualizada',
        'descricao' => 'Gramática completa da Língua Portuguesa com exercícios de fixação e redação.',
        'tipo' => 'livro',
        'categoria' => 'Português',
        'disciplina_id' => 2,
        'autor' => 'Maria Santos',
        'editora' => 'Editora Letras',
        'destaque' => 1
    ],
    [
        'titulo' => 'Física - Ondulatória e Termodinâmica',
        'descricao' => 'Estudo aprofundado de ondas, som, calor e termodinâmica com exercícios resolvidos.',
        'tipo' => 'livro',
        'categoria' => 'Física',
        'disciplina_id' => 3,
        'autor' => 'Dr. Carlos Alberto',
        'editora' => 'Editora Ciência',
        'destaque' => 0
    ],
    [
        'titulo' => 'Química Geral e Inorgânica',
        'descricao' => 'Introdução à química, tabela periódica, ligações químicas e funções inorgânicas.',
        'tipo' => 'livro',
        'categoria' => 'Química',
        'disciplina_id' => 4,
        'autor' => 'Profa. Ana Paula',
        'editora' => 'Editora Ciência',
        'destaque' => 0
    ],
    [
        'titulo' => 'História de Angola',
        'descricao' => 'História completa de Angola desde os primórdios até a atualidade.',
        'tipo' => 'livro',
        'categoria' => 'História',
        'disciplina_id' => 5,
        'autor' => 'Dr. António Costa',
        'editora' => 'Editora Angola',
        'destaque' => 1
    ],
    [
        'titulo' => 'Geografia - Meio Ambiente e Sociedade',
        'descricao' => 'Estudo das relações entre sociedade e meio ambiente, climatologia e geopolítica.',
        'tipo' => 'livro',
        'categoria' => 'Geografia',
        'disciplina_id' => 6,
        'autor' => 'Profa. Carla Mendes',
        'editora' => 'Editora Terra',
        'destaque' => 0
    ],
    
    // Apostilas
    [
        'titulo' => 'Apostila de Exercícios - Matemática 10ª Classe',
        'descricao' => 'Apostila com 200 exercícios resolvidos e propostos de Matemática para a 10ª Classe.',
        'tipo' => 'apostila',
        'categoria' => 'Matemática',
        'disciplina_id' => 1,
        'autor' => 'Prof. João Silva',
        'destaque' => 1
    ],
    [
        'titulo' => 'Apostila de Redação - Como escrever bem',
        'descricao' => 'Técnicas de redação, tipos de texto e exercícios práticos de escrita.',
        'tipo' => 'apostila',
        'categoria' => 'Português',
        'disciplina_id' => 2,
        'autor' => 'Maria Santos',
        'destaque' => 0
    ],
    
    // Vídeos
    [
        'titulo' => 'Vídeo Aula - Equações do 2º Grau',
        'descricao' => 'Vídeo aula explicando como resolver equações do 2º grau usando a fórmula de Bhaskara.',
        'tipo' => 'video',
        'categoria' => 'Matemática',
        'disciplina_id' => 1,
        'autor' => 'Prof. João Silva',
        'destaque' => 1
    ],
    [
        'titulo' => 'Vídeo Aula - Reações Químicas',
        'descricao' => 'Explicação sobre os tipos de reações químicas e balanceamento de equações.',
        'tipo' => 'video',
        'categoria' => 'Química',
        'disciplina_id' => 4,
        'autor' => 'Profa. Ana Paula',
        'destaque' => 0
    ],
    [
        'titulo' => 'Vídeo Aula - Independência de Angola',
        'descricao' => 'Documentário sobre o processo de independência de Angola.',
        'tipo' => 'video',
        'categoria' => 'História',
        'disciplina_id' => 5,
        'autor' => 'Dr. António Costa',
        'destaque' => 1
    ],
    
    // Apresentações
    [
        'titulo' => 'Slides - Funções Matemáticas',
        'descricao' => 'Apresentação sobre funções do 1º e 2º grau, gráficos e propriedades.',
        'tipo' => 'apresentacao',
        'categoria' => 'Matemática',
        'disciplina_id' => 1,
        'autor' => 'Prof. João Silva',
        'destaque' => 0
    ],
    [
        'titulo' => 'Slides - Sistema Solar',
        'descricao' => 'Apresentação sobre o sistema solar, planetas e movimentos celestes.',
        'tipo' => 'apresentacao',
        'categoria' => 'Ciências',
        'disciplina_id' => 3,
        'autor' => 'Dr. Carlos Alberto',
        'destaque' => 0
    ],
    
    // Exercícios
    [
        'titulo' => 'Lista de Exercícios - Geometria Plana',
        'descricao' => 'Lista com 50 exercícios sobre áreas, perímetros e teorema de Pitágoras.',
        'tipo' => 'exercicio',
        'categoria' => 'Matemática',
        'disciplina_id' => 1,
        'autor' => 'Prof. João Silva',
        'destaque' => 1
    ],
    [
        'titulo' => 'Lista de Exercícios - Concordância Verbal',
        'descricao' => 'Exercícios sobre concordância verbal e nominal com gabarito.',
        'tipo' => 'exercicio',
        'categoria' => 'Português',
        'disciplina_id' => 2,
        'autor' => 'Maria Santos',
        'destaque' => 0
    ],
    
    // Provas
    [
        'titulo' => 'Prova Modelo - Matemática 1º Bimestre',
        'descricao' => 'Modelo de prova para o 1º bimestre com questões de múltipla escolha e discursivas.',
        'tipo' => 'prova',
        'categoria' => 'Matemática',
        'disciplina_id' => 1,
        'autor' => 'Prof. João Silva',
        'destaque' => 1
    ],
    [
        'titulo' => 'Prova Modelo - Português 2º Bimestre',
        'descricao' => 'Modelo de prova de Português com interpretação de texto e gramática.',
        'tipo' => 'prova',
        'categoria' => 'Português',
        'disciplina_id' => 2,
        'autor' => 'Maria Santos',
        'destaque' => 0
    ]
];

$inseridos = 0;
foreach ($materiais as $material) {
    $sql = "INSERT INTO materiais_didaticos (
                escola_id, titulo, descricao, tipo, categoria, 
                disciplina_id, autor, editora, destaque, status
            ) VALUES (
                :escola_id, :titulo, :descricao, :tipo, :categoria,
                :disciplina_id, :autor, :editora, :destaque, 'ativo'
            )";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':titulo' => $material['titulo'],
        ':descricao' => $material['descricao'],
        ':tipo' => $material['tipo'],
        ':categoria' => $material['categoria'],
        ':disciplina_id' => $material['disciplina_id'],
        ':autor' => $material['autor'],
        ':editora' => $material['editora'] ?? null,
        ':destaque' => $material['destaque']
    ]);
    $inseridos++;
}

echo "<p>✅ Inseridos $inseridos materiais didáticos.</p>";

// ============================================
// 2. INSERIR AVALIAÇÕES
// ============================================

// Buscar materiais inseridos
$materiais_ids = $conn->query("SELECT id FROM materiais_didaticos ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

$avaliacoes = [
    ['nota' => 5, 'comentario' => 'Excelente material! Muito útil para as aulas.'],
    ['nota' => 4, 'comentario' => 'Bom conteúdo, mas poderia ter mais exemplos.'],
    ['nota' => 5, 'comentario' => 'Ótimo! Recomendo para todos os professores.'],
    ['nota' => 3, 'comentario' => 'Material razoável, faltam alguns tópicos importantes.'],
    ['nota' => 4, 'comentario' => 'Muito bom, usei com meus alunos e adoraram.'],
    ['nota' => 5, 'comentario' => 'Completo e bem explicado. Parabéns!'],
    ['nota' => 4, 'comentario' => 'Útil, mas precisa de atualizações.'],
    ['nota' => 5, 'comentario' => 'Excelente recurso para sala de aula.']
];

$avaliacoes_inseridas = 0;
foreach ($materiais_ids as $mat_id) {
    // Cada material recebe 2-3 avaliações aleatórias
    $num_avaliacoes = rand(2, 3);
    for ($i = 0; $i < $num_avaliacoes; $i++) {
        $avaliacao = $avaliacoes[array_rand($avaliacoes)];
        $sql = "INSERT INTO avaliacoes_materiais (material_id, funcionario_id, nota, comentario) 
                VALUES (:material_id, :funcionario_id, :nota, :comentario)
                ON DUPLICATE KEY UPDATE nota = :nota, comentario = :comentario";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':material_id' => $mat_id,
            ':funcionario_id' => $funcionario_id,
            ':nota' => $avaliacao['nota'],
            ':comentario' => $avaliacao['comentario']
        ]);
        $avaliacoes_inseridas++;
    }
    
    // Atualizar média do material
    $conn->prepare("UPDATE materiais_didaticos SET avaliacao_media = (SELECT AVG(nota) FROM avaliacoes_materiais WHERE material_id = :material_id) WHERE id = :material_id")
        ->execute([':material_id' => $mat_id]);
}

echo "<p>✅ Inseridas $avaliacoes_inseridas avaliações.</p>";

// ============================================
// 3. INSERIR FAVORITOS
// ============================================

$favoritos_inseridos = 0;
// Adicionar alguns materiais aos favoritos
$favoritos = array_slice($materiais_ids, 0, 5);
foreach ($favoritos as $mat_id) {
    $sql = "INSERT IGNORE INTO favoritos_materiais (material_id, funcionario_id) VALUES (:material_id, :funcionario_id)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':material_id' => $mat_id,
        ':funcionario_id' => $funcionario_id
    ]);
    $favoritos_inseridos++;
}

echo "<p>✅ Inseridos $favoritos_inseridos favoritos.</p>";

// ============================================
// 4. INSERIR EMPRÉSTIMOS
// ============================================

$emprestimos_inseridos = 0;
$materiais_emprestimo = array_slice($materiais_ids, 6, 4);
foreach ($materiais_emprestimo as $mat_id) {
    $data_emprestimo = date('Y-m-d', strtotime('-' . rand(5, 20) . ' days'));
    $data_devolucao_prevista = date('Y-m-d', strtotime($data_emprestimo . ' + ' . rand(7, 14) . ' days'));
    $status = rand(0, 1) ? 'emprestado' : 'devolvido';
    $data_devolucao_real = $status == 'devolvido' ? date('Y-m-d', strtotime($data_devolucao_prevista . ' - ' . rand(0, 3) . ' days')) : null;
    
    $sql = "INSERT INTO emprestimo_materiais (material_id, funcionario_id, data_emprestimo, data_devolucao_prevista, data_devolucao_real, quantidade, status) 
            VALUES (:material_id, :funcionario_id, :data_emprestimo, :data_devolucao_prevista, :data_devolucao_real, :quantidade, :status)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':material_id' => $mat_id,
        ':funcionario_id' => $funcionario_id,
        ':data_emprestimo' => $data_emprestimo,
        ':data_devolucao_prevista' => $data_devolucao_prevista,
        ':data_devolucao_real' => $data_devolucao_real,
        ':quantidade' => rand(1, 2),
        ':status' => $status
    ]);
    $emprestimos_inseridos++;
}

echo "<p>✅ Inseridos $emprestimos_inseridos empréstimos.</p>";

// ============================================
// 5. ATUALIZAR ESTATÍSTICAS (visualizações e downloads)
// ============================================

foreach ($materiais_ids as $mat_id) {
    $visualizacoes = rand(10, 500);
    $downloads = rand(5, 200);
    $conn->prepare("UPDATE materiais_didaticos SET visualizacoes = :visualizacoes, downloads = :downloads WHERE id = :id")
        ->execute([
            ':visualizacoes' => $visualizacoes,
            ':downloads' => $downloads,
            ':id' => $mat_id
        ]);
}

echo "<p>✅ Estatísticas atualizadas (visualizações e downloads).</p>";

// ============================================
// RESUMO FINAL
// ============================================

echo "<hr>";
echo "<h3>📊 Resumo da Inserção:</h3>";
echo "<ul>";
echo "<li>📚 Materiais didáticos: <strong>$inseridos</strong></li>";
echo "<li>⭐ Avaliações: <strong>$avaliacoes_inseridas</strong></li>";
echo "<li>❤️ Favoritos: <strong>$favoritos_inseridos</strong></li>";
echo "<li>📖 Empréstimos: <strong>$emprestimos_inseridos</strong></li>";
echo "</ul>";

echo "<div class='alert alert-success'>";
echo "<i class='fas fa-check-circle'></i> <strong>Dados inseridos com sucesso!</strong><br>";
echo "<a href='biblioteca.php' class='btn btn-primary mt-3'>Ir para Biblioteca Virtual</a>";
echo "</div>";

// Mostrar alguns dados inseridos
echo "<h3>📋 Materiais Inseridos:</h3>";
echo "<table class='table table-bordered'>";
echo "<tr><th>ID</th><th>Título</th><th>Tipo</th><th>Categoria</th><th>Destaque</th></tr>";
$materiais_lista = $conn->query("SELECT id, titulo, tipo, categoria, destaque FROM materiais_didaticos ORDER BY id")->fetchAll();
foreach ($materiais_lista as $m) {
    echo "<tr>";
    echo "<td>{$m['id']}</td>";
    echo "<td>" . htmlspecialchars($m['titulo']) . "</td>";
    echo "<td>{$m['tipo']}</td>";
    echo "<td>{$m['categoria']}</td>";
    echo "<td>" . ($m['destaque'] ? '✅' : '❌') . "</td>";
    echo "</tr>";
}
echo "</table>";
?>