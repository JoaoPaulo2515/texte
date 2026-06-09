<?php
// teste_proposta.php - Arquivo de teste para identificar o erro
require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

echo "<h2>Diagnóstico do Sistema de Propostas de Prova</h2>";

// 1. Verificar funcionário
echo "<h3>1. Verificando Funcionário:</h3>";
$sql = "SELECT id, nome, cargo FROM funcionarios WHERE id = :id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $professor_id]);
$func = $stmt->fetch(PDO::FETCH_ASSOC);
if ($func) {
    echo "<p style='color:green'>✅ Funcionário encontrado: ID={$func['id']}, Nome={$func['nome']}</p>";
} else {
    echo "<p style='color:red'>❌ Funcionário NÃO encontrado com ID: $professor_id</p>";
    // Buscar primeiro funcionário
    $stmt2 = $conn->query("SELECT id, nome FROM funcionarios LIMIT 1");
    $primeiro = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($primeiro) {
        echo "<p>👉 Use este ID: {$primeiro['id']} - Nome: {$primeiro['nome']}</p>";
    }
}

// 2. Verificar tabela propostas_prova
echo "<h3>2. Verificando Tabela propostas_prova:</h3>";
$check = $conn->query("SHOW TABLES LIKE 'propostas_prova'");
if ($check->rowCount() > 0) {
    echo "<p style='color:green'>✅ Tabela 'propostas_prova' existe</p>";
    
    // Mostrar estrutura
    $cols = $conn->query("DESCRIBE propostas_prova");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th></tr>";
    while ($col = $cols->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>❌ Tabela 'propostas_prova' NÃO existe</p>";
    echo "<p>Criando tabela...</p>";
    $conn->exec("
        CREATE TABLE propostas_prova (
            id INT PRIMARY KEY AUTO_INCREMENT,
            funcionario_id INT NOT NULL,
            escola_id INT NOT NULL,
            ano_letivo_id INT NOT NULL,
            turma_id INT NOT NULL,
            disciplina_id INT NOT NULL,
            bimestre INT NOT NULL,
            tipo_prova VARCHAR(30) DEFAULT 'normal',
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            conteudo TEXT NOT NULL,
            data_prevista DATE NOT NULL,
            duracao INT DEFAULT 60,
            peso DECIMAL(5,2) DEFAULT 10,
            anexo VARCHAR(255),
            status VARCHAR(20) DEFAULT 'pendente',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p style='color:green'>✅ Tabela criada com sucesso!</p>";
}

// 3. Verificar professor_disciplina_turma
echo "<h3>3. Verificando associação Professor-Disciplina-Turma:</h3>";
$sql = "SELECT * FROM professor_disciplina_turma WHERE professor_id = :id LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $professor_id]);
$associacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($associacoes) > 0) {
    echo "<p style='color:green'>✅ Existem " . count($associacoes) . " associações</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>funcionario_id</th><th>turma_id</th><th>disciplina_id</th></tr>";
    foreach ($associacoes as $a) {
        echo "<tr><td>{$a['id']}</td><td>{$a['professor_id']}</td><td>{$a['turma_id']}</td><td>{$a['disciplina_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>❌ Nenhuma associação encontrada para funcionario_id = $professor_id</p>";
    
    // Buscar turmas e disciplinas disponíveis
    $turmas = $conn->query("SELECT id, nome FROM turmas LIMIT 3")->fetchAll();
    $disciplinas = $conn->query("SELECT id, nome FROM disciplinas LIMIT 3")->fetchAll();
    
    if (count($turmas) > 0 && count($disciplinas) > 0) {
        echo "<p>Criando associação de teste...</p>";
        $insert = $conn->prepare("INSERT INTO professor_disciplina_turma (professor_id, turma_id, disciplina_id) VALUES (:func, :turma, :disc)");
        $insert->execute([
            ':func' => $professor_id,
            ':turma' => $turmas[0]['id'],
            ':disc' => $disciplinas[0]['id']
        ]);
        echo "<p style='color:green'>✅ Associação de teste criada!</p>";
    }
}

// 4. Testar inserção direta
echo "<h3>4. Testando inserção direta:</h3>";

$teste_data = [
    'funcionario_id' => $professor_id,
    'escola_id' => $escola_id,
    'ano_letivo_id' => 1,
    'turma_id' => 1,
    'disciplina_id' => 1,
    'bimestre' => 1,
    'tipo_prova' => 'normal',
    'titulo' => 'TESTE DIRETO',
    'descricao' => 'Teste de inserção direta',
    'conteudo' => 'Conteúdo de teste',
    'data_prevista' => date('Y-m-d'),
    'duracao' => 60,
    'peso' => 10,
    'status' => 'pendente'
];

try {
    $sql = "INSERT INTO propostas_prova (
                funcionario_id, escola_id, ano_letivo_id, turma_id, disciplina_id,
                bimestre, tipo_prova, titulo, descricao, conteudo, data_prevista,
                duracao, peso, status
            ) VALUES (
                :funcionario_id, :escola_id, :ano_letivo_id, :turma_id, :disciplina_id,
                :bimestre, :tipo_prova, :titulo, :descricao, :conteudo, :data_prevista,
                :duracao, :peso, :status
            )";
    
    $stmt = $conn->prepare($sql);
    $result = $stmt->execute($teste_data);
    
    if ($result) {
        $id = $conn->lastInsertId();
        echo "<p style='color:green'>✅ Inserção direta funcionou! ID: $id</p>";
        
        // Limpar teste
        $conn->prepare("DELETE FROM propostas_prova WHERE id = :id")->execute([':id' => $id]);
        echo "<p>Registro de teste removido.</p>";
    } else {
        echo "<p style='color:red'>❌ Falha na inserção direta</p>";
        echo "<pre>Erro: " . print_r($stmt->errorInfo(), true) . "</pre>";
    }
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ Erro: " . $e->getMessage() . "</p>";
}

// 5. Verificar campos do formulário
echo "<h3>5. Dicas para resolver:</h3>";
echo "<ul>";
echo "<li>Certifique-se de que o <strong>funcionario_id</strong> ($professor_id) existe na tabela funcionarios</li>";
echo "<li>Certifique-se de que a tabela <strong>propostas_prova</strong> tem a coluna <strong>disciplina_id</strong></li>";
echo "<li>Se a coluna 'disciplina_id' não existir, execute: <br><code>ALTER TABLE propostas_prova ADD COLUMN disciplina_id INT NOT NULL AFTER turma_id;</code></li>";
echo "<li>Se a coluna 'conteudo' não for TEXT, execute: <br><code>ALTER TABLE propostas_prova MODIFY COLUMN conteudo TEXT NOT NULL;</code></li>";
echo "</ul>";

echo "<hr>";
echo "<a href='proposta_prova.php' class='btn btn-primary'>Voltar para Proposta de Prova</a>";
?>