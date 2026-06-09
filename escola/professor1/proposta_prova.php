<?php
// escola/professor/proposta_prova.php - Submeter Proposta de Prova

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR ID DO FUNCIONARIO (professor) - CORRIGIDO
// ============================================
// Como a tabela professores não existe, usamos diretamente o ID do funcionário
$funcionario_id = $professor_id;
$funcionario_nome = '';

// Buscar dados do funcionário na tabela funcionarios
$sql_func = "SELECT id, nome, cargo FROM funcionarios WHERE id = :funcionario_id AND escola_id = :escola_id LIMIT 1";
$stmt_func = $conn->prepare($sql_func);
$stmt_func->execute([
    ':funcionario_id' => $funcionario_id,
    ':escola_id' => $escola_id
]);
$funcionario = $stmt_func->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    // Se não encontrar, buscar o primeiro funcionário da escola
    $sql_func2 = "SELECT id, nome, cargo FROM funcionarios WHERE escola_id = :escola_id LIMIT 1";
    $stmt_func2 = $conn->prepare($sql_func2);
    $stmt_func2->execute([':escola_id' => $escola_id]);
    $funcionario = $stmt_func2->fetch(PDO::FETCH_ASSOC);
    $funcionario_id = $funcionario ? $funcionario['id'] : $professor_id;
}

$funcionario_nome = $funcionario ? $funcionario['nome'] : '';

// ============================================
// INICIALIZAR VARIÁVEIS
// ============================================
$success = '';
$error = '';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->query($sql_ano);
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_atual = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR TURMAS DO PROFESSOR - CORRIGIDO
// ============================================
$sql_turmas = "
    SELECT DISTINCT t.id, t.nome, t.ano, t.turno
    FROM turmas t
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
    WHERE pdt.professor_id = :funcionario_id AND t.status = 'ativa'
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':funcionario_id' => $funcionario_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// TIPOS DE PROVA
// ============================================
$tipos_prova = [
    'normal' => 'Prova Normal',
    'recuperacao' => 'Prova de Recuperação',
    'exame' => 'Exame Final',
    'recurso' => 'Prova de Recurso',
    'especial' => 'Prova Especial'
];

// ============================================
// VERIFICAR SE A TABELA EXISTE E CRIAR SE NECESSÁRIO
// ============================================
try {
    $check_table = $conn->query("SHOW TABLES LIKE 'propostas_prova'");
    if ($check_table->rowCount() == 0) {
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
                parecer TEXT,
                aprovado_por INT,
                data_aprovacao DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
    }
} catch (PDOException $e) {
    error_log("Erro ao criar tabela: " . $e->getMessage());
}

// ============================================
// PROCESSAR SUBMISSÃO DA PROPOSTA
// ============================================
// ============================================
// PROCESSAR SUBMISSÃO DA PROPOSTA - COM DEBUG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submeter_proposta'])) {
    
    // ============================================
    // DEBUG: VERIFICAR DADOS RECEBIDOS
    // ============================================
    error_log("=== PROPOSTA PROVA - FORMULÁRIO ENVIADO ===");
    error_log("POST: " . print_r($_POST, true));
    error_log("FILES: " . print_r($_FILES, true));
    
    $turma_id = (int)$_POST['turma_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $bimestre = (int)$_POST['bimestre'];
    $tipo_prova = $_POST['tipo_prova'] ?? 'normal';
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao']);
    $conteudo = $_POST['conteudo'] ?? '';
    $data_prevista = $_POST['data_prevista'] ?? '';
    $duracao = (int)$_POST['duracao'] ?? 60;
    $peso = (float)$_POST['peso'] ?? 10;
    
    // DEBUG: Valores recebidos
    error_log("Turma ID: $turma_id");
    error_log("Disciplina ID: $disciplina_id");
    error_log("Bimestre: $bimestre");
    error_log("Título: $titulo");
    error_log("Data Prevista: $data_prevista");
    error_log("Funcionario ID: $funcionario_id");
    error_log("Escola ID: $escola_id");
    error_log("Ano Letivo ID: $ano_letivo_id");
    
    // Validações
    $errors = [];
    if ($turma_id <= 0) $errors[] = "Turma não selecionada";
    if ($disciplina_id <= 0) $errors[] = "Disciplina não selecionada";
    if ($bimestre < 1 || $bimestre > 4) $errors[] = "Bimestre inválido";
    if (empty($titulo)) $errors[] = "Título vazio";
    if (empty($conteudo) || $conteudo == '<p><br></p>') $errors[] = "Conteúdo vazio";
    if (empty($data_prevista)) $errors[] = "Data prevista vazia";
    if ($funcionario_id <= 0) $errors[] = "Funcionário não encontrado";
    
    if (!empty($errors)) {
        $error = "⚠️ " . implode(", ", $errors);
        error_log("ERROS: " . $error);
    } else {
        // Validar se o professor leciona a disciplina na turma
        $sql_check = "
            SELECT id FROM professor_disciplina_turma 
            WHERE funcionario_id = :funcionario_id 
            AND turma_id = :turma_id 
            AND disciplina_id = :disciplina_id
            LIMIT 1
        ";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':funcionario_id' => $funcionario_id,
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id
        ]);
        
        error_log("Check professor_disciplina_turma: " . $stmt_check->rowCount() . " registros encontrados");
        
        if ($stmt_check->rowCount() == 0) {
            $error = "⚠️ Você não está associado a esta combinação de Turma e Disciplina.";
            error_log("ERRO: Combinação inválida - funcionario_id: $funcionario_id, turma_id: $turma_id, disciplina_id: $disciplina_id");
        } else {
            try {
                $conn->beginTransaction();
                
                // Upload de arquivo
                $arquivo_path = null;
                if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../../uploads/propostas_prova/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                        error_log("Pasta criada: $upload_dir");
                    }
                    
                    $extensao = strtolower(pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION));
                    $extensoes_permitidas = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                    
                    if (in_array($extensao, $extensoes_permitidas)) {
                        $nome_arquivo = 'proposta_' . time() . '_' . uniqid() . '.' . $extensao;
                        $arquivo_path = 'uploads/propostas_prova/' . $nome_arquivo;
                        $caminho_completo = $upload_dir . $nome_arquivo;
                        if (move_uploaded_file($_FILES['anexo']['tmp_name'], $caminho_completo)) {
                            error_log("Arquivo salvo: $caminho_completo");
                        } else {
                            error_log("ERRO ao mover arquivo");
                        }
                    }
                }
                
                // Inserir proposta
                $sql = "INSERT INTO propostas_prova (
                            funcionario_id, escola_id, ano_letivo_id,
                            turma_id, disciplina_id, bimestre, tipo_prova,
                            titulo, descricao, conteudo, data_prevista,
                            duracao, peso, anexo, status
                        ) VALUES (
                            :funcionario_id, :escola_id, :ano_letivo_id,
                            :turma_id, :disciplina_id, :bimestre, :tipo_prova,
                            :titulo, :descricao, :conteudo, :data_prevista,
                            :duracao, :peso, :anexo, 'pendente'
                        )";
                
                error_log("SQL: " . $sql);
                
                $stmt = $conn->prepare($sql);
                $result = $stmt->execute([
                    ':funcionario_id' => $funcionario_id,
                    ':escola_id' => $escola_id,
                    ':ano_letivo_id' => $ano_letivo_id,
                    ':turma_id' => $turma_id,
                    ':disciplina_id' => $disciplina_id,
                    ':bimestre' => $bimestre,
                    ':tipo_prova' => $tipo_prova,
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':conteudo' => $conteudo,
                    ':data_prevista' => $data_prevista,
                    ':duracao' => $duracao,
                    ':peso' => $peso,
                    ':anexo' => $arquivo_path
                ]);
                
                if ($result) {
                    $proposta_id = $conn->lastInsertId();
                    error_log("PROPOSTA INSERIDA COM SUCESSO! ID: $proposta_id");
                    $conn->commit();
                    $success = "✅ Proposta de prova enviada com sucesso! Aguarde a aprovação da coordenação.";
                } else {
                    error_log("ERRO: Falha ao inserir - " . print_r($stmt->errorInfo(), true));
                    throw new Exception("Falha ao inserir na base de dados");
                }
                
            } catch (PDOException $e) {
                $conn->rollBack();
                $error = "Erro ao enviar proposta: " . $e->getMessage();
                error_log("Erro PDO: " . $e->getMessage());
            } catch (Exception $e) {
                $conn->rollBack();
                $error = $e->getMessage();
                error_log("Erro: " . $e->getMessage());
            }
        }
    }
}

// ============================================
// AJAX: BUSCAR DISCIPLINAS POR TURMA - CORRIGIDO
// ============================================
if (isset($_GET['ajax_disciplinas']) && isset($_GET['turma_id'])) {
    $turma_id = (int)$_GET['turma_id'];
    
    $sql = "
        SELECT DISTINCT d.id, d.nome
        FROM disciplinas d
        INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
        WHERE pdt.professor_id = :funcionario_id AND pdt.turma_id = :turma_id
        ORDER BY d.nome
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':funcionario_id' => $funcionario_id,
        ':turma_id' => $turma_id
    ]);
    $disciplinas_ajax = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($disciplinas_ajax);
    exit;
}

// ============================================
// FUNÇÃO PARA PREVIEW DA PROPOSTA
// ============================================
if (isset($_GET['preview']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $sql = "
        SELECT p.*, 
               t.nome as turma_nome, t.ano,
               d.nome as disciplina_nome,
               f.nome as professor_nome
        FROM propostas_prova p
        INNER JOIN turmas t ON t.id = p.turma_id
        INNER JOIN disciplinas d ON d.id = p.disciplina_id
        INNER JOIN funcionarios f ON f.id = p.funcionario_id
        WHERE p.id = :id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id]);
    $proposta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($proposta) {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Preview - ' . htmlspecialchars($proposta['titulo']) . '</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <script src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-MML-AM_CHTML"></script>
            <style>
                body { padding: 40px; font-family: Arial, sans-serif; }
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #006B3E; }
                .info { background: #f5f5f5; padding: 15px; border-radius: 8px; margin: 20px 0; }
                .conteudo { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 8px; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
                @media print { body { padding: 20px; } .no-print { display: none; } }
            </style>
        </head>
        <body>
            <div class="no-print text-end mb-3">
                <button class="btn btn-primary" onclick="window.print()">Imprimir</button>
                <button class="btn btn-secondary" onclick="window.close()">Fechar</button>
            </div>
            <div class="header">
                <h2>Proposta de Prova</h2>
                <p>Protocolo: PROV-' . str_pad($proposta['id'], 6, '0', STR_PAD_LEFT) . '</p>
            </div>
            <div class="info">
                <table class="table table-bordered">
                    <tr><td width="30%"><strong>Professor:</strong></td><td>' . htmlspecialchars($proposta['professor_nome']) . '</td></tr>
                    <tr><td><strong>Disciplina:</strong></td><td>' . htmlspecialchars($proposta['disciplina_nome']) . '</td></tr>
                    <tr><td><strong>Turma:</strong></td><td>' . htmlspecialchars($proposta['turma_nome']) . '</td></tr>
                    <tr><td><strong>Data Prevista:</strong></td><td>' . date('d/m/Y', strtotime($proposta['data_prevista'])) . '</td></tr>
                    <tr><td><strong>Status:</strong></td><td>' . ucfirst($proposta['status']) . '</td></tr>
                </table>
            </div>
            <div class="conteudo">
                <h4>' . htmlspecialchars($proposta['titulo']) . '</h4>
                <hr>
                ' . $proposta['conteudo'] . '
            </div>
            <div class="footer">
                <p>Documento gerado pelo SIGE Angola em ' . date('d/m/Y H:i:s') . '</p>
            </div>
        </body>
        </html>';
        exit;
    }
}

// ============================================
// BUSCAR PROPOSTAS ANTERIORES
// ============================================
$sql_propostas = "
    SELECT p.*,
           t.nome as turma_nome, t.ano,
           d.nome as disciplina_nome
    FROM propostas_prova p
    INNER JOIN turmas t ON t.id = p.turma_id
    INNER JOIN disciplinas d ON d.id = p.disciplina_id
    WHERE p.funcionario_id = :funcionario_id
    ORDER BY p.created_at DESC
    LIMIT 20
";
$stmt_propostas = $conn->prepare($sql_propostas);
$stmt_propostas->execute([':funcionario_id' => $funcionario_id]);
$propostas = $stmt_propostas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_propostas = count($propostas);
$total_pendente = 0;
$total_aprovado = 0;
$total_reprovado = 0;

foreach ($propostas as $prop) {
    if ($prop['status'] == 'pendente') $total_pendente++;
    if ($prop['status'] == 'aprovado') $total_aprovado++;
    if ($prop['status'] == 'reprovado') $total_reprovado++;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getStatusBadgeProposta($status) {
    switch ($status) {
        case 'pendente':
            return '<span class="badge bg-warning text-dark"> Pendente</span>';
        case 'aprovado':
            return '<span class="badge bg-success"> Aprovado</span>';
        case 'reprovado':
            return '<span class="badge bg-danger"> Reprovado</span>';
        case 'revisao':
            return '<span class="badge bg-info"> Revisão</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getTipoProvaBadge($tipo) {
    switch ($tipo) {
        case 'normal': return '<span class="badge bg-primary">Normal</span>';
        case 'recuperacao': return '<span class="badge bg-warning text-dark">Recuperação</span>';
        case 'exame': return '<span class="badge bg-danger">Exame Final</span>';
        case 'recurso': return '<span class="badge bg-info">Recurso</span>';
        case 'especial': return '<span class="badge bg-secondary">Especial</span>';
        default: return '<span class="badge bg-secondary">' . $tipo . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proposta de Prova | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
    <style>
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .info-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .info-title { font-size: 1.1em; font-weight: bold; color: #006B3E; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #006B3E; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 28px; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 12px; color: #666; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; border: none; text-decoration: none; }
        .btn-enviar { background: #28a745; color: white; border-radius: 25px; padding: 10px 30px; border: none; font-weight: bold; }
        .btn-enviar:hover { background: #1e7e34; color: white; }
        .btn-ajuda { background: #fd7e14; color: white; border-radius: 25px; padding: 8px 20px; border: none; }
        .btn-preview { background: #17a2b8; color: white; border-radius: 20px; padding: 5px 15px; font-size: 12px; border: none; }
        .main-content { margin-left: 280px; padding: 20px; background: #f5f7fb; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .proposta-card { background: white; border-radius: 12px; margin-bottom: 15px; padding: 15px; border-left: 4px solid #ffc107; transition: transform 0.2s; }
        .proposta-card:hover { transform: translateX(5px); }
        .proposta-card.aprovado { border-left-color: #28a745; }
        .proposta-card.reprovado { border-left-color: #dc3545; }
        .upload-area { border: 2px dashed #ddd; border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .upload-area:hover { border-color: #006B3E; background: #f5f5f5; }
        .help-step { display: flex; align-items: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; }
        .help-number { width: 40px; height: 40px; background: #006B3E; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px; margin-right: 15px; }
        .alerta-info { background: #e8f5e9; border-left: 4px solid #28a745; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        
        .custom-toolbar-buttons {
            background: #f8f9fa;
            padding: 8px;
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .custom-toolbar-buttons .btn {
            margin: 2px;
            font-size: 12px;
            padding: 5px 10px;
        }
        .custom-toolbar-buttons .btn i {
            margin-right: 5px;
        }
        .note-editor .note-toolbar {
            background: #f8f9fa;
            border-bottom: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-file-alt"></i> Proposta de Prova</h2>
                    <p>Submeta propostas de prova para aprovação da coordenação pedagógica</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn-voltar btn me-2"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <button type="button" class="btn-ajuda btn" data-bs-toggle="modal" data-bs-target="#modalAjuda"><i class="fas fa-question-circle"></i> Como Funciona</button>
                </div>
            </div>
        </div>
        
        <div class="alerta-info">
            <i class="fas fa-info-circle text-success"></i> 
            <strong> Informação Importante:</strong><br>
            As propostas de prova devem ser submetidas com pelo menos 5 dias de antecedência da data prevista.
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-edit"></i> Nova Proposta de Prova</div>
                    <form method="POST" id="formProposta" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Turma *</label>
                            <select name="turma_id" id="turma_id" class="form-select" required>
                                <option value="">Selecione a turma...</option>
                                <?php foreach ($turmas as $turma): ?>
                                <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?> - <?php echo $turma['ano']; ?> (<?php echo $turma['turno']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Disciplina *</label>
                            <select name="disciplina_id" id="disciplina_id" class="form-select" required>
                                <option value="">Primeiro selecione a turma...</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bimestre *</label>
                                <select name="bimestre" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <option value="1">1º Bimestre</option>
                                    <option value="2">2º Bimestre</option>
                                    <option value="3">3º Bimestre</option>
                                    <option value="4">4º Bimestre</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Prova *</label>
                                <select name="tipo_prova" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($tipos_prova as $key => $nome): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $nome; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Título da Proposta *</label>
                            <input type="text" name="titulo" class="form-control" placeholder="Ex: Prova de Matemática - 1º Bimestre" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição / Justificativa</label>
                            <textarea name="descricao" class="form-control" rows="3" placeholder="Descreva os objetivos da prova, conteúdos abordados, etc."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Conteúdo da Prova *</label>
                            <div class="custom-toolbar-buttons">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="inserirFormula()" title="Inserir Fórmula Matemática">
                                    <i class="fas fa-square-root-alt"></i> Fórmula
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="inserirFigura()" title="Inserir Figura Geométrica">
                                    <i class="fas fa-shapes"></i> Figura
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="inserirTabela()" title="Inserir Tabela">
                                    <i class="fas fa-table"></i> Tabela
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="inserirMatriz()" title="Inserir Matriz">
                                    <i class="fas fa-border-all"></i> Matriz
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="inserirExercicio()" title="Inserir Exercício">
                                    <i class="fas fa-pencil-alt"></i> Exercício
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="inserirAlternativas()" title="Inserir Questão Múltipla Escolha">
                                    <i class="fas fa-check-circle"></i> Alternativas
                                </button>
                            </div>
                            <textarea name="conteudo" id="conteudo" class="form-control summernote" rows="10"></textarea>
                            <small class="text-muted">Utilize os botões acima para inserir fórmulas matemáticas, figuras, tabelas, matrizes e exercícios.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data Prevista *</label>
                                <input type="date" name="data_prevista" id="data_prevista" class="form-control" required>
                                <small class="text-muted">Mínimo 5 dias de antecedência</small>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Duração (min)</label>
                                <input type="number" name="duracao" class="form-control" value="60" min="30" max="180">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Peso na Média</label>
                                <input type="number" step="0.5" name="peso" class="form-control" value="10" min="0" max="100">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Anexar Documento (Opcional)</label>
                            <div class="upload-area" id="uploadArea" onclick="document.getElementById('anexo').click()">
                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                <p class="mb-0">Clique para fazer upload ou arraste arquivos aqui</p>
                                <small class="text-muted">Formatos: PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</small>
                                <input type="file" name="anexo" id="anexo" style="display: none;" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            </div>
                            <div id="fileInfo" class="mt-2" style="display: none;">
                                <div class="alert alert-info">
                                    <i class="fas fa-file"></i> <span id="fileName"></span>
                                    <button type="button" class="btn-close float-end" onclick="removerArquivo()"></button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning small">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Atenção:</strong> Após enviar, a proposta será analisada pela coordenação.
                        </div>
                        
                        <button type="button" class="btn btn-enviar w-100" onclick="confirmarEnvio()">
                            <i class="fas fa-paper-plane"></i> Submeter Proposta
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="row">
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number"><?php echo $total_propostas; ?></div><div class="stat-label">Total</div></div></div>
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number text-warning"><?php echo $total_pendente; ?></div><div class="stat-label">Pendentes</div></div></div>
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number text-success"><?php echo $total_aprovado; ?></div><div class="stat-label">Aprovadas</div></div></div>
                </div>
                
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-history"></i> Histórico de Propostas
                        <button class="btn btn-sm btn-outline-secondary float-end" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Atualizar</button>
                    </div>
                    <?php if (empty($propostas)): ?>
                        <p class="text-muted text-center">Nenhuma proposta encontrada.</p>
                    <?php else: ?>
                        <?php foreach ($propostas as $prop): ?>
                        <div class="proposta-card <?php echo $prop['status']; ?>">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <strong><?php echo htmlspecialchars($prop['titulo']); ?></strong>
                                    <br>
                                    <small><?php echo htmlspecialchars($prop['disciplina_nome']); ?> - <?php echo htmlspecialchars($prop['turma_nome']); ?></small>
                                </div>
                                <div>
                                    <?php echo getStatusBadgeProposta($prop['status']); ?>
                                    <?php echo getTipoProvaBadge($prop['tipo_prova']); ?>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small><i class="fas fa-calendar"></i> Data: <?php echo formatarData($prop['data_prevista']); ?></small><br>
                                <small><i class="fas fa-clock"></i> Duração: <?php echo $prop['duracao']; ?> min</small>
                                <br>
                                <button class="btn btn-preview btn-sm mt-1" onclick="visualizarPreview(<?php echo $prop['id']; ?>)">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Como Funciona?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="help-step"><div class="help-number">1</div><div class="help-content"><h6>Preencher Formulário</h6><p>Informe a turma, disciplina, bimestre e conteúdo da prova.</p></div></div>
                    <div class="help-step"><div class="help-number">2</div><div class="help-content"><h6>Formatar Conteúdo</h6><p>Use os botões coloridos acima do editor para inserir fórmulas, figuras, tabelas, matrizes e exercícios.</p></div></div>
                    <div class="help-step"><div class="help-number">3</div><div class="help-content"><h6>Submeter</h6><p>Envie para análise da coordenação pedagógica.</p></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button></div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade" id="modalConfirmacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #28a745; color: white;">
                    <h5 class="modal-title">Confirmar Submissão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja submeter esta proposta?</p>
                    <div class="alert alert-info">
                        <span id="confirm_resumo"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarEnvio">Sim, Submeter</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-MML-AM_CHTML"></script>
    
    <script>
        $(document).ready(function() {
            $('#conteudo').summernote({
                height: 300,
                toolbar: [
                    ['style', ['style', 'p', 'h1', 'h2', 'h3']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['fontname', ['fontname']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['height', ['height']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'undo', 'redo']]
                ]
            });
            
            let dataMinima = new Date();
            dataMinima.setDate(dataMinima.getDate() + 5);
            $('#data_prevista').attr('min', dataMinima.toISOString().split('T')[0]);
            
            $('#turma_id').change(function() {
                let turmaId = $(this).val();
                let disciplinaSelect = $('#disciplina_id');
                
                if (!turmaId) {
                    disciplinaSelect.html('<option value="">Primeiro selecione a turma...</option>');
                    return;
                }
                
                disciplinaSelect.html('<option value="">Carregando...</option>');
                
                $.ajax({
                    url: window.location.href,
                    method: 'GET',
                    data: { ajax_disciplinas: 1, turma_id: turmaId },
                    dataType: 'json',
                    success: function(data) {
                        disciplinaSelect.html('<option value="">Selecione a disciplina...</option>');
                        if (data.length > 0) {
                            $.each(data, function(i, disc) {
                                disciplinaSelect.append('<option value="' + disc.id + '">' + disc.nome + '</option>');
                            });
                        } else {
                            disciplinaSelect.append('<option value="">Nenhuma disciplina encontrada</option>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Erro:', error);
                        disciplinaSelect.html('<option value="">Erro ao carregar disciplinas</option>');
                    }
                });
            });
        });
        
        function inserirFormula() {
            var formula = prompt('Digite a fórmula matemática em LaTeX:', '\\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}');
            if (formula) {
                var html = '<div class="formula" style="text-align:center; padding:10px; background:#f0f0f0; margin:10px 0; border-radius:5px;">';
                html += '\\[' + formula + '\\]';
                html += '</div>';
                $('#conteudo').summernote('pasteHTML', html);
            }
        }
        
        function inserirFigura() {
            var tipo = prompt('Figura:\n1-Triângulo\n2-Quadrado\n3-Círculo\n4-Trapézio', '1');
            var html = '<div class="figura" style="text-align:center; margin:10px 0; padding:10px; background:#f8f9fa; border-radius:5px;">';
            html += '<p><strong>Figura Geométrica</strong></p>';
            html += '<p>Base: ____ cm | Altura: ____ cm | Área: ____ cm²</p>';
            html += '</div>';
            $('#conteudo').summernote('pasteHTML', html);
        }
        
        function inserirTabela() {
            var linhas = prompt('Número de linhas:', '3');
            var colunas = prompt('Número de colunas:', '4');
            if (linhas && colunas) {
                var html = '<table class="table table-bordered" style="width:100%; border-collapse:collapse;">';
                html += '<thead class="table-dark"><tr>';
                for (var c = 0; c < colunas; c++) html += '<th>Coluna ' + (c+1) + '</th>';
                html += '<tr></thead><tbody>';
                for (var l = 1; l < linhas; l++) {
                    html += '<tr>';
                    for (var c = 0; c < colunas; c++) html += '<td>____</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                $('#conteudo').summernote('pasteHTML', html);
            }
        }
        
        function inserirMatriz() {
            var tipo = prompt('Matriz:\n1-2x2\n2-3x3\n3-Determinante', '1');
            var html = '<div class="matriz" style="text-align:center; padding:10px; background:#f0f0f0; margin:10px 0;">';
            if (tipo == '1') html += '\\[ \\begin{pmatrix} a & b \\\\ c & d \\end{pmatrix} \\]';
            else if (tipo == '2') html += '\\[ \\begin{pmatrix} a & b & c \\\\ d & e & f \\\\ g & h & i \\end{pmatrix} \\]';
            else html += '\\[ \\det \\begin{pmatrix} a & b \\\\ c & d \\end{pmatrix} = ad - bc \\]';
            html += '</div>';
            $('#conteudo').summernote('pasteHTML', html);
        }
        
        function inserirExercicio() {
            var tipo = prompt('Exercício:\n1-Exercício\n2-Problema\n3-Equação', '1');
            var html = '<div class="exercicio" style="margin:15px 0; padding:10px; border-left:4px solid #006B3E; background:#f8f9fa;">';
            html += '<strong>Exercício:</strong><br><br>_________________________________<br><br>';
            html += '<strong>Resolução:</strong><br>_________________________________</div>';
            $('#conteudo').summernote('pasteHTML', html);
        }
        
        function inserirAlternativas() {
            var enunciado = prompt('Enunciado da questão:', 'Questão:');
            if (enunciado) {
                var html = '<div class="questao" style="margin:20px 0; padding:15px; border:1px solid #ddd; border-radius:10px; background:#fef9e6;">';
                html += '<p><strong>' + enunciado + '</strong></p>';
                html += '<div class="alternativas">';
                html += '<div><strong>A)</strong> _________________________________</div>';
                html += '<div><strong>B)</strong> _________________________________</div>';
                html += '<div><strong>C)</strong> _________________________________</div>';
                html += '<div><strong>D)</strong> _________________________________</div>';
                html += '<div><strong>E)</strong> _________________________________</div>';
                html += '</div><div class="mt-2"><strong>Resposta:</strong> ______</div>';
                html += '</div>';
                $('#conteudo').summernote('pasteHTML', html);
            }
        }
        
        function visualizarPreview(id) {
            window.open('proposta_prova.php?preview=1&id=' + id, '_blank', 'width=900,height=700');
        }
        
        $('#anexo').change(function() {
            if (this.files && this.files[0]) {
                $('#fileName').text(this.files[0].name);
                $('#fileInfo').show();
            }
        });
        
        $('.upload-area').on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
        $('.upload-area').on('dragleave', function(e) { $(this).removeClass('dragover'); });
        $('.upload-area').on('drop', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
            var files = e.originalEvent.dataTransfer.files;
            if (files.length) {
                $('#anexo')[0].files = files;
                $('#anexo').trigger('change');
            }
        });
        
        function removerArquivo() {
            $('#anexo').val('');
            $('#fileInfo').hide();
        }
        
       function confirmarEnvio() {
    if (!$('#turma_id').val()) { 
        showNotification('warning', 'Selecione uma turma.');
        return false; 
    }
    if (!$('#disciplina_id').val()) { 
        showNotification('warning', 'Selecione uma disciplina.');
        return false; 
    }
    if ($('select[name="bimestre"]').val() == '') { 
        showNotification('warning', 'Selecione o bimestre.');
        return false; 
    }
    if ($('input[name="titulo"]').val() == '') { 
        showNotification('warning', 'Informe o título da prova.');
        return false; 
    }
    if ($('#conteudo').summernote('isEmpty')) { 
        showNotification('warning', 'Descreva o conteúdo da prova.');
        return false; 
    }
    if ($('#data_prevista').val() == '') { 
        showNotification('warning', 'Informe a data prevista.');
        return false; 
    }
    
    // Mostrar resumo no modal
    $('#confirm_resumo').html(
        '📚 Turma: ' + $('#turma_id option:selected').text() + '<br>' +
        '📖 Disciplina: ' + $('#disciplina_id option:selected').text() + '<br>' +
        '📅 Data: ' + $('#data_prevista').val() + '<br>' +
        '⏱️ Duração: ' + $('input[name="duracao"]').val() + ' minutos'
    );
    
    new bootstrap.Modal(document.getElementById('modalConfirmacao')).show();
    return true;
}

// Envio do formulário com loading
$('#btnConfirmarEnvio').click(function() {
    // Fechar modal
    $('#modalConfirmacao').modal('hide');
    
    // Mostrar loading
    showLoading();
    
    // Enviar formulário
    $('#formProposta').submit();
});

// Se houver erro ou sucesso do PHP, as notificações já aparecerão via $(document).ready
        
        $('#btnConfirmarEnvio').click(function() {
            $('#formProposta').submit(); 
        });



        // Função para mostrar notificação estilo toast
function showNotification(type, message) {
    // Criar elemento de notificação
    var toastHTML = `
        <div class="toast-notification" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
            <div class="alert alert-${type} alert-dismissible fade show shadow-lg" style="border-radius: 10px;">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        ${type === 'success' ? '<i class="fas fa-check-circle fa-2x"></i>' : '<i class="fas fa-exclamation-triangle fa-2x"></i>'}
                    </div>
                    <div class="flex-grow-1">
                        <strong>${type === 'success' ? 'Sucesso!' : 'Erro!'}</strong><br>
                        <small>${message}</small>
                    </div>
                    <button type="button" class="btn-close" onclick="this.closest('.toast-notification').remove()"></button>
                </div>
            </div>
        </div>
    `;
    
    // Remover notificações antigas
    $('.toast-notification').remove();
    
    // Adicionar nova notificação
    $('body').append(toastHTML);
    
    // Auto fechar após 5 segundos
    setTimeout(function() {
        $('.toast-notification').fadeOut(500, function() {
            $(this).remove();
        });
    }, 5000);
}

// Função para mostrar loading
function showLoading() {
    var loadingHTML = `
        <div class="loading-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9998; display: flex; align-items: center; justify-content: center;">
            <div class="bg-white p-4 rounded-3 shadow-lg text-center">
                <div class="spinner-border text-success mb-2" role="status" style="width: 40px; height: 40px;">
                    <span class="visually-hidden">Carregando...</span>
                </div>
                <p class="mb-0">Enviando proposta...</p>
            </div>
        </div>
    `;
    $('.loading-overlay').remove();
    $('body').append(loadingHTML);
}

function hideLoading() {
    $('.loading-overlay').fadeOut(300, function() {
        $(this).remove();
    });
}

// Verificar se há mensagem da sessão (PHP)
$(document).ready(function() {
    <?php if (isset($success) && !empty($success)): ?>
        showNotification('success', '<?php echo addslashes($success); ?>');
    <?php endif; ?>
    
    <?php if (isset($error) && !empty($error)): ?>
        showNotification('danger', '<?php echo addslashes($error); ?>');
    <?php endif; ?>
});
    </script>
</body>
</html>