<?php
# Via linha de comando
//php scripts/importar_estudantes.php

# Via navegador
//http://localhost/sige_Plataforma/escola/importar_estudantes.php
// escola/importar_estudantes.php - Importar estudantes via navegador

require_once __DIR__ . '/../config/database.php';
session_start();

// Verificar autenticação
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar turmas
$sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar ano letivo ativo
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $turma_id = (int)$_POST['turma_id'];
    $criar_usuario = isset($_POST['criar_usuario']);
    $estudantes_json = $_POST['estudantes_json'] ?? '';
    
    if (empty($turma_id)) {
        $erro = "Selecione uma turma";
    } elseif (empty($estudantes_json)) {
        $erro = "Informe os dados dos estudantes";
    } else {
        $estudantes = json_decode($estudantes_json, true);
        
        if (empty($estudantes)) {
            $erro = "Dados dos estudantes inválidos";
        } else {
            try {
                $conn->beginTransaction();
                
                $inseridos = 0;
                $erros = [];
                
                foreach ($estudantes as $estudante) {
                    try {
                        // Inserir estudante
                        $sql = "INSERT INTO estudantes (
                                    nome, bi, genero, data_nascimento, pai_nome, mae_nome,
                                    telefone, email, endereco, naturalidade, nacionalidade,
                                    status, escola_id, data_registro
                                ) VALUES (
                                    :nome, :bi, :genero, :data_nascimento, :pai_nome, :mae_nome,
                                    :telefone, :email, :endereco, :naturalidade, :nacionalidade,
                                    'ativo', :escola_id, NOW()
                                )";
                        
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([
                            ':nome' => $estudante['nome'],
                            ':bi' => $estudante['bi'] ?? null,
                            ':genero' => $estudante['genero'] ?? 'masculino',
                            ':data_nascimento' => $estudante['data_nascimento'] ?? null,
                            ':pai_nome' => $estudante['pai_nome'] ?? null,
                            ':mae_nome' => $estudante['mae_nome'] ?? null,
                            ':telefone' => $estudante['telefone'] ?? null,
                            ':email' => $estudante['email'] ?? null,
                            ':endereco' => $estudante['endereco'] ?? null,
                            ':naturalidade' => $estudante['naturalidade'] ?? null,
                            ':nacionalidade' => $estudante['nacionalidade'] ?? 'Angolana',
                            ':escola_id' => $escola_id
                        ]);
                        
                        $estudante_id = $conn->lastInsertId();
                        
                        // Criar matrícula
                        $numero_matricula = gerarNumeroMatricula($conn, $ano_letivo_ano, $turma_id);
                        
                        $sql_mat = "INSERT INTO matriculas (
                                        estudante_id, turma_id, ano_letivo, ano_letivo_id,
                                        numero_matricula, data_matricula, status, escola_id, data_criacao
                                    ) VALUES (
                                        :estudante_id, :turma_id, :ano_letivo, :ano_letivo_id,
                                        :numero_matricula, CURDATE(), 'ativa', :escola_id, NOW()
                                    )";
                        
                        $stmt_mat = $conn->prepare($sql_mat);
                        $stmt_mat->execute([
                            ':estudante_id' => $estudante_id,
                            ':turma_id' => $turma_id,
                            ':ano_letivo' => $ano_letivo_ano,
                            ':ano_letivo_id' => $ano_letivo_id,
                            ':numero_matricula' => $numero_matricula,
                            ':escola_id' => $escola_id
                        ]);
                        
                        // Criar usuário
                        if ($criar_usuario) {
                            $email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $estudante['nome'])) . '@aluno.escola.com';
                            $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $estudante['nome'])) . '_' . $numero_matricula;
                            $senha = password_hash($numero_matricula, PASSWORD_DEFAULT);
                            
                            $sql_user = "INSERT INTO usuarios (
                                            nome, email, senha, username, tipo, escola_id,
                                            referencia_id, status, data_criacao
                                        ) VALUES (
                                            :nome, :email, :senha, :username, 'aluno',
                                            :escola_id, :referencia_id, 'ativo', NOW()
                                        )";
                            
                            $stmt_user = $conn->prepare($sql_user);
                            $stmt_user->execute([
                                ':nome' => $estudante['nome'],
                                ':email' => $email,
                                ':senha' => $senha,
                                ':username' => $username,
                                ':escola_id' => $escola_id,
                                ':referencia_id' => $estudante_id
                            ]);
                        }
                        
                        $inseridos++;
                        
                    } catch (PDOException $e) {
                        $erros[] = $estudante['nome'] . ': ' . $e->getMessage();
                    }
                }
                
                $conn->commit();
                $mensagem = "$inseridos estudantes importados com sucesso!";
                if (!empty($erros)) {
                    $erro = "Erros: " . implode('; ', $erros);
                }
                
            } catch (Exception $e) {
                $conn->rollBack();
                $erro = "Erro na importação: " . $e->getMessage();
            }
        }
    }
}

function gerarNumeroMatricula($conn, $ano, $turma_id) {
    $sql_turma = "SELECT nome, ano FROM turmas WHERE id = :id";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':id' => $turma_id]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    $prefixo = $ano . substr($turma['nome'], 0, 2) . str_pad($turma['ano'], 2, '0', STR_PAD_LEFT);
    
    $sql = "SELECT numero_matricula FROM matriculas WHERE numero_matricula LIKE :prefixo ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':prefixo' => $prefixo . '%']);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo) {
        $numero = (int)substr($ultimo['numero_matricula'], -4) + 1;
    } else {
        $numero = 1;
    }
    
    return $prefixo . str_pad($numero, 4, '0', STR_PAD_LEFT);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Estudantes | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); border-radius: 15px; padding: 25px 30px; margin-bottom: 25px; color: white; }
        .card { border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .table-preview th { background: #006B3E; color: white; }
        .btn-import { background: #006B3E; color: white; border-radius: 25px; padding: 10px 30px; }
        .btn-import:hover { background: #004d2d; color: white; }
    </style>
</head>
<body>
    <?php include 'menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2><i class="fas fa-upload"></i> Importar Estudantes</h2>
            <p>Importação em massa de estudantes para matrícula e criação de usuários</p>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success"><?php echo $mensagem; ?></div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger"><?php echo $erro; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Instruções</h5>
            </div>
            <div class="card-body">
                <p>Utilize o formato JSON para importar os dados. Exemplo:</p>
                <pre class="bg-light p-3">
[
    {
        "nome": "João Silva",
        "bi": "001234567LA042",
        "genero": "masculino",
        "data_nascimento": "2010-05-15",
        "pai_nome": "Manuel Silva",
        "mae_nome": "Maria Silva",
        "telefone": "923456789",
        "email": "joao@email.com",
        "endereco": "Rua 1, Luanda",
        "naturalidade": "Luanda",
        "nacionalidade": "Angolana"
    }
]</pre>
            </div>
        </div>
        
        <form method="POST" class="mt-4">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Turma *</label>
                            <select name="turma_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($turmas as $turma): ?>
                                <option value="<?php echo $turma['id']; ?>">
                                    <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">
                                <input type="checkbox" name="criar_usuario" value="1" checked>
                                Criar usuários automaticamente
                            </label>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">Dados dos Estudantes (JSON) *</label>
                            <textarea name="estudantes_json" class="form-control" rows="15" required placeholder='[{"nome": "João Silva", "bi": "001234567LA042", ...}]'></textarea>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-center">
                    <button type="submit" class="btn btn-import">
                        <i class="fas fa-upload"></i> Importar Estudantes
                    </button>
                </div>
            </div>
        </form>
        
        <div class="card mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-download"></i> Template de Importação</h5>
            </div>
            <div class="card-body">
                <a href="download_template.php" class="btn btn-outline-success">
                    <i class="fas fa-file-excel"></i> Baixar Template (Excel/CSV)
                </a>
                <a href="download_template_json.php" class="btn btn-outline-primary">
                    <i class="fas fa-code"></i> Baixar Template (JSON)
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>