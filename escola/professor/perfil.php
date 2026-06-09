<?php
// escola/professor/perfil.php - Perfil do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// BUSCAR DADOS DO FUNCIONÁRIO (PROFESSOR)
// ============================================
// Verificar estrutura da tabela funcionarios primeiro
try {
    $check_columns = $conn->query("SHOW COLUMNS FROM funcionarios");
    $colunas_funcionario = [];
    while ($col = $check_columns->fetch(PDO::FETCH_ASSOC)) {
        $colunas_funcionario[] = $col['Field'];
    }
} catch (PDOException $e) {
    $colunas_funcionario = [];
}

// Construir SELECT dinâmico baseado nas colunas existentes
$select_funcionario = "f.id, f.usuario_id, f.escola_id, f.tipo_funcionario";
$select_funcionario .= in_array('bi', $colunas_funcionario) ? ", f.bi" : "";
$select_funcionario .= in_array('telefone', $colunas_funcionario) ? ", f.telefone" : "";
$select_funcionario .= in_array('foto', $colunas_funcionario) ? ", f.foto" : "";
$select_funcionario .= in_array('data_nascimento', $colunas_funcionario) ? ", f.data_nascimento" : "";
$select_funcionario .= in_array('genero', $colunas_funcionario) ? ", f.genero" : "";
$select_funcionario .= in_array('data_admissao', $colunas_funcionario) ? ", f.data_admissao" : "";
$select_funcionario .= in_array('cargo', $colunas_funcionario) ? ", f.cargo" : "";
$select_funcionario .= in_array('formacao', $colunas_funcionario) ? ", f.formacao" : "";
$select_funcionario .= in_array('especialidade', $colunas_funcionario) ? ", f.especialidade" : "";
$select_funcionario .= in_array('endereco', $colunas_funcionario) ? ", f.endereco" : "";
$select_funcionario .= in_array('banco', $colunas_funcionario) ? ", f.banco" : "";
$select_funcionario .= in_array('agencia', $colunas_funcionario) ? ", f.agencia" : "";
$select_funcionario .= in_array('conta', $colunas_funcionario) ? ", f.conta" : "";
$select_funcionario .= in_array('created_at', $colunas_funcionario) ? ", f.created_at" : "";

$sql = "
    SELECT 
        $select_funcionario,
        u.id as usuario_id,
        u.nome,
        u.email as usuario_email,
        u.telefone as usuario_telefone,
        u.status as usuario_status,
        e.nome as escola_nome,
        e.logo as escola_logo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    INNER JOIN escolas e ON e.id = f.escola_id
    WHERE f.id = :funcionario_id 
    AND f.escola_id = :escola_id 
    AND f.tipo_funcionario = 'professor'
";

$stmt = $conn->prepare($sql);
$stmt->execute([':funcionario_id' => $professor_id, ':escola_id' => $escola_id]);
$funcionario_dados = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$funcionario_dados) {
    die('Professor não encontrado');
}

// Garantir que campos obrigatórios existam
$funcionario_dados['nome'] = $funcionario_dados['nome'] ?? '';
$funcionario_dados['email'] = $funcionario_dados['usuario_email'] ?? '';
$funcionario_dados['telefone'] = $funcionario_dados['telefone'] ?? $funcionario_dados['usuario_telefone'] ?? '';

// ============================================
// ATUALIZAR PERFIL
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['acao']) && $_POST['acao'] == 'atualizar_dados') {
        $nome = $_POST['nome'] ?? '';
        $email = $_POST['email'] ?? '';
        $telefone = $_POST['telefone'] ?? '';
        $bi = $_POST['bi'] ?? '';
        $data_nascimento = $_POST['data_nascimento'] ?? '';
        $genero = $_POST['genero'] ?? '';
        $endereco = $_POST['endereco'] ?? '';
        $formacao = $_POST['formacao'] ?? '';
        $especialidade = $_POST['especialidade'] ?? '';
        
        // Upload da foto
        $foto = $funcionario_dados['foto'] ?? '';
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
            $upload_dir = __DIR__ . '/../../uploads/funcionarios/fotos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            $nome_foto = 'funcionario_' . $professor_id . '_' . time() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $nome_foto)) {
                // Apagar foto antiga
                if (!empty($foto) && file_exists($upload_dir . $foto)) {
                    unlink($upload_dir . $foto);
                }
                $foto = $nome_foto;
            }
        }
        
        try {
            // Atualizar funcionarios
            $updates_funcionario = [];
            $params_funcionario = [':id' => $professor_id, ':escola_id' => $escola_id];
            
            if (in_array('bi', $colunas_funcionario)) {
                $updates_funcionario[] = "bi = :bi";
                $params_funcionario[':bi'] = $bi;
            }
            if (in_array('telefone', $colunas_funcionario)) {
                $updates_funcionario[] = "telefone = :telefone";
                $params_funcionario[':telefone'] = $telefone;
            }
            if (in_array('data_nascimento', $colunas_funcionario)) {
                $updates_funcionario[] = "data_nascimento = :data_nascimento";
                $params_funcionario[':data_nascimento'] = $data_nascimento ?: null;
            }
            if (in_array('genero', $colunas_funcionario)) {
                $updates_funcionario[] = "genero = :genero";
                $params_funcionario[':genero'] = $genero;
            }
            if (in_array('endereco', $colunas_funcionario)) {
                $updates_funcionario[] = "endereco = :endereco";
                $params_funcionario[':endereco'] = $endereco;
            }
            if (in_array('formacao', $colunas_funcionario)) {
                $updates_funcionario[] = "formacao = :formacao";
                $params_funcionario[':formacao'] = $formacao;
            }
            if (in_array('especialidade', $colunas_funcionario)) {
                $updates_funcionario[] = "especialidade = :especialidade";
                $params_funcionario[':especialidade'] = $especialidade;
            }
            if (in_array('foto', $colunas_funcionario)) {
                $updates_funcionario[] = "foto = :foto";
                $params_funcionario[':foto'] = $foto;
            }
            
            if (!empty($updates_funcionario)) {
                $updates_funcionario[] = "updated_at = NOW()";
                $sql_funcionario = "UPDATE funcionarios SET " . implode(", ", $updates_funcionario) . " WHERE id = :id AND escola_id = :escola_id AND tipo_funcionario = 'professor'";
                $stmt = $conn->prepare($sql_funcionario);
                $stmt->execute($params_funcionario);
            }
            
            // Atualizar usuarios
            $stmt = $conn->prepare("
                UPDATE usuarios SET 
                    nome = :nome,
                    email = :email,
                    telefone = :telefone,
                    updated_at = NOW()
                WHERE id = :usuario_id AND escola_id = :escola_id
            ");
            $stmt->execute([
                ':nome' => $nome,
                ':email' => $email,
                ':telefone' => $telefone,
                ':usuario_id' => $usuario_id,
                ':escola_id' => $escola_id
            ]);
            
            $_SESSION['usuario_nome'] = $nome;
            $success = "Perfil atualizado com sucesso!";
            
            // Recarregar dados
            $stmt = $conn->prepare($sql);
            $stmt->execute([':funcionario_id' => $professor_id, ':escola_id' => $escola_id]);
            $funcionario_dados = $stmt->fetch(PDO::FETCH_ASSOC);
            $funcionario_dados['nome'] = $funcionario_dados['nome'] ?? '';
            $funcionario_dados['email'] = $funcionario_dados['usuario_email'] ?? '';
            $funcionario_dados['telefone'] = $funcionario_dados['telefone'] ?? $funcionario_dados['usuario_telefone'] ?? '';
            
        } catch (PDOException $e) {
            $error = "Erro ao atualizar perfil: " . $e->getMessage();
        }
    }
    
    // Alterar senha
    if (isset($_POST['acao']) && $_POST['acao'] == 'alterar_senha') {
        $senha_atual = $_POST['senha_atual'] ?? '';
        $nova_senha = $_POST['nova_senha'] ?? '';
        $confirmar_senha = $_POST['confirmar_senha'] ?? '';
        
        if (empty($senha_atual) || empty($nova_senha) || empty($confirmar_senha)) {
            $error = "Preencha todos os campos de senha.";
        } elseif ($nova_senha !== $confirmar_senha) {
            $error = "A nova senha e a confirmação não coincidem.";
        } elseif (strlen($nova_senha) < 6) {
            $error = "A nova senha deve ter no mínimo 6 caracteres.";
        } else {
            // Verificar senha atual
            $stmt = $conn->prepare("SELECT senha FROM usuarios WHERE id = :usuario_id");
            $stmt->execute([':usuario_id' => $usuario_id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($senha_atual, $usuario['senha'])) {
                $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE usuarios SET senha = :senha, updated_at = NOW() WHERE id = :usuario_id");
                $stmt->execute([':senha' => $nova_senha_hash, ':usuario_id' => $usuario_id]);
                $success = "Senha alterada com sucesso!";
            } else {
                $error = "Senha atual incorreta.";
            }
        }
    }
    
    // Atualizar dados bancários
    if (isset($_POST['acao']) && $_POST['acao'] == 'atualizar_banco') {
        $banco = $_POST['banco'] ?? '';
        $agencia = $_POST['agencia'] ?? '';
        $conta = $_POST['conta'] ?? '';
        
        try {
            $updates_banco = [];
            $params_banco = [':id' => $professor_id, ':escola_id' => $escola_id];
            
            if (in_array('banco', $colunas_funcionario)) {
                $updates_banco[] = "banco = :banco";
                $params_banco[':banco'] = $banco;
            }
            if (in_array('agencia', $colunas_funcionario)) {
                $updates_banco[] = "agencia = :agencia";
                $params_banco[':agencia'] = $agencia;
            }
            if (in_array('conta', $colunas_funcionario)) {
                $updates_banco[] = "conta = :conta";
                $params_banco[':conta'] = $conta;
            }
            
            if (!empty($updates_banco)) {
                $updates_banco[] = "updated_at = NOW()";
                $sql_banco = "UPDATE funcionarios SET " . implode(", ", $updates_banco) . " WHERE id = :id AND escola_id = :escola_id AND tipo_funcionario = 'professor'";
                $stmt = $conn->prepare($sql_banco);
                $stmt->execute($params_banco);
                $success = "Dados bancários atualizados com sucesso!";
            }
            
            // Recarregar dados
            $stmt = $conn->prepare($sql);
            $stmt->execute([':funcionario_id' => $professor_id, ':escola_id' => $escola_id]);
            $funcionario_dados = $stmt->fetch(PDO::FETCH_ASSOC);
            $funcionario_dados['nome'] = $funcionario_dados['nome'] ?? '';
            $funcionario_dados['email'] = $funcionario_dados['usuario_email'] ?? '';
            $funcionario_dados['telefone'] = $funcionario_dados['telefone'] ?? $funcionario_dados['usuario_telefone'] ?? '';
            
        } catch (PDOException $e) {
            $error = "Erro ao atualizar dados bancários: " . $e->getMessage();
        }
    }
}

// Formatar data para input
function formatarDataInput($data) {
    if (empty($data)) return '';
    return date('Y-m-d', strtotime($data));
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .profile-photo {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #006B3E;
            margin-bottom: 15px;
        }
        .info-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .info-card h5 {
            color: #006B3E;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #006B3E;
        }
        .info-label {
            font-weight: 600;
            color: #555;
            width: 140px;
            display: inline-block;
        }
        .info-row {
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
        }
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        .btn-primary:hover {
            background: #004d2d;
        }
        .nav-tabs .nav-link {
            color: #006B3E;
        }
        .nav-tabs .nav-link.active {
            background-color: #006B3E;
            color: white;
            border-color: #006B3E;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-user-circle"></i> Meu Perfil</h2>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Coluna da Foto -->
            <div class="col-md-3">
                <div class="info-card text-center">
                    <?php if (!empty($funcionario_dados['foto']) && file_exists('../../uploads/funcionarios/fotos/' . $funcionario_dados['foto'])): ?>
                        <img src="../../uploads/funcionarios/fotos/<?php echo $funcionario_dados['foto']; ?>" class="profile-photo">
                    <?php else: ?>
                        <img src="../../assets/images/avatar-professor.png" class="profile-photo">
                    <?php endif; ?>
                    <h5><?php echo htmlspecialchars($funcionario_dados['nome']); ?></h5>
                    <p class="text-muted"><?php echo htmlspecialchars($funcionario_dados['cargo'] ?? 'Professor'); ?></p>
                    <hr>
                    <div class="text-start">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-envelope"></i> Email:</span>
                            <span><?php echo htmlspecialchars($funcionario_dados['email']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-phone"></i> Telefone:</span>
                            <span><?php echo htmlspecialchars($funcionario_dados['telefone'] ?? 'Não informado'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-calendar"></i> Admissão:</span>
                            <span><?php echo !empty($funcionario_dados['data_admissao']) ? date('d/m/Y', strtotime($funcionario_dados['data_admissao'])) : 'Não informado'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-building"></i> Escola:</span>
                            <span><?php echo htmlspecialchars($funcionario_dados['escola_nome']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Coluna dos Dados -->
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header bg-white">
                        <ul class="nav nav-tabs card-header-tabs" id="perfilTabs" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dadosPessoais">
                                    <i class="fas fa-user"></i> Dados Pessoais
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosAcademicos">
                                    <i class="fas fa-graduation-cap"></i> Formação
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosBancarios">
                                    <i class="fas fa-university"></i> Dados Bancários
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#alterarSenha">
                                    <i class="fas fa-key"></i> Alterar Senha
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- Dados Pessoais -->
                            <div class="tab-pane fade show active" id="dadosPessoais">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="acao" value="atualizar_dados">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Nome Completo</label>
                                            <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($funcionario_dados['nome']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($funcionario_dados['email']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Telefone</label>
                                            <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($funcionario_dados['telefone'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">BI / Documento</label>
                                            <input type="text" name="bi" class="form-control" value="<?php echo htmlspecialchars($funcionario_dados['bi'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Data Nascimento</label>
                                            <input type="date" name="data_nascimento" class="form-control" value="<?php echo formatarDataInput($funcionario_dados['data_nascimento'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Género</label>
                                            <select name="genero" class="form-control">
                                                <option value="">Selecione...</option>
                                                <option value="M" <?php echo ($funcionario_dados['genero'] ?? '') == 'M' ? 'selected' : ''; ?>>Masculino</option>
                                                <option value="F" <?php echo ($funcionario_dados['genero'] ?? '') == 'F' ? 'selected' : ''; ?>>Feminino</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Endereço</label>
                                        <textarea name="endereco" class="form-control" rows="2"><?php echo htmlspecialchars($funcionario_dados['endereco'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Foto</label>
                                        <input type="file" name="foto" class="form-control" accept="image/*">
                                        <small class="text-muted">Formatos permitidos: JPG, PNG, GIF. Tamanho máximo: 2MB</small>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Salvar Alterações
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Formação Acadêmica -->
                            <div class="tab-pane fade" id="dadosAcademicos">
                                <form method="POST">
                                    <input type="hidden" name="acao" value="atualizar_dados">
                                    <div class="mb-3">
                                        <label class="form-label">Formação Acadêmica</label>
                                        <textarea name="formacao" class="form-control" rows="4" placeholder="Ex: Licenciatura em Matemática - Universidade Agostinho Neto (2010-2014)"><?php echo htmlspecialchars($funcionario_dados['formacao'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Especialidade / Área de atuação</label>
                                        <input type="text" name="especialidade" class="form-control" value="<?php echo htmlspecialchars($funcionario_dados['especialidade'] ?? ''); ?>" placeholder="Ex: Matemática, Física, Química">
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Salvar Alterações
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Dados Bancários -->
                            <div class="tab-pane fade" id="dadosBancarios">
                                <form method="POST">
                                    <input type="hidden" name="acao" value="atualizar_banco">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Banco</label>
                                            <select name="banco" class="form-control">
                                                <option value="">Selecione o banco...</option>
                                                <option value="BAI" <?php echo ($funcionario_dados['banco'] ?? '') == 'BAI' ? 'selected' : ''; ?>>BAI - Banco Angolano de Investimentos</option>
                                                <option value="BFA" <?php echo ($funcionario_dados['banco'] ?? '') == 'BFA' ? 'selected' : ''; ?>>BFA - Banco de Fomento Angola</option>
                                                <option value="BNA" <?php echo ($funcionario_dados['banco'] ?? '') == 'BNA' ? 'selected' : ''; ?>>BNA - Banco Nacional de Angola</option>
                                                <option value="BIC" <?php echo ($funcionario_dados['banco'] ?? '') == 'BIC' ? 'selected' : ''; ?>>Banco BIC</option>
                                                <option value="BE" <?php echo ($funcionario_dados['banco'] ?? '') == 'BE' ? 'selected' : ''; ?>>Banco Económico</option>
                                                <option value="SOL" <?php echo ($funcionario_dados['banco'] ?? '') == 'SOL' ? 'selected' : ''; ?>>Banco Sol</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Agência</label>
                                            <input type="text" name="agencia" class="form-control" value="<?php echo htmlspecialchars($funcionario_dados['agencia'] ?? ''); ?>" placeholder="Nº da Agência">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Nº de Conta</label>
                                            <input type="text" name="conta" class="form-control" value="<?php echo htmlspecialchars($funcionario_dados['conta'] ?? ''); ?>" placeholder="Número da Conta">
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Salvar Dados Bancários
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Alterar Senha -->
                            <div class="tab-pane fade" id="alterarSenha">
                                <form method="POST">
                                    <input type="hidden" name="acao" value="alterar_senha">
                                    <div class="mb-3">
                                        <label class="form-label">Senha Atual</label>
                                        <input type="password" name="senha_atual" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nova Senha</label>
                                        <input type="password" name="nova_senha" class="form-control" required>
                                        <small class="text-muted">Mínimo de 6 caracteres</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Confirmar Nova Senha</label>
                                        <input type="password" name="confirmar_senha" class="form-control" required>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-key"></i> Alterar Senha
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>