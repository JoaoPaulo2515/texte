<?php
// escola/professor/meu_perfil.php - Perfil do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR DADOS DO PROFESSOR
// ============================================
$sql_professor = "
    SELECT p.*, u.email, u.nome 
    FROM funcionarios p
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.id = :professor_id
";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':professor_id' => $professor_id]);
$professor_dados = $stmt_professor->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR ALTERAÇÃO DE SENHA
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alterar_senha'])) {
    $senha_atual = $_POST['senha_atual'] ?? '';
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    if ($senha_atual && $nova_senha && $confirmar_senha) {
        if ($nova_senha !== $confirmar_senha) {
            $error = "As senhas não coincidem.";
        } elseif (strlen($nova_senha) < 6) {
            $error = "A nova senha deve ter pelo menos 6 caracteres.";
        } else {
            $sql_check = "SELECT senha FROM usuarios WHERE id = :usuario_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':usuario_id' => $professor_dados['usuario_id']]);
            $user = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($senha_atual, $user['senha'])) {
                $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $sql_update = "UPDATE usuarios SET senha = :senha, updated_at = NOW() WHERE id = :usuario_id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':senha' => $nova_senha_hash, ':usuario_id' => $professor_dados['usuario_id']]);
                $success = "Senha alterada com sucesso!";
            } else {
                $error = "Senha atual incorreta.";
            }
        }
    } else {
        $error = "Preencha todos os campos de senha.";
    }
}

// ============================================
// PROCESSAR ATUALIZAÇÃO DE FOTO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_foto'])) {
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'gif'];
        $arquivo = $_FILES['foto'];
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        
        if (in_array($extensao, $extensoes_permitidas)) {
            $diretorio = '../../uploads/professores/fotos/';
            if (!file_exists($diretorio)) {
                mkdir($diretorio, 0777, true);
            }
            
            $nome_arquivo = 'professor_' . $professor_id . '_' . time() . '.' . $extensao;
            $caminho_completo = $diretorio . $nome_arquivo;
            
            if (move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
                $sql = "UPDATE professores SET foto = :foto WHERE id = :professor_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':foto' => $nome_arquivo, ':professor_id' => $professor_id]);
                $success = "Foto atualizada com sucesso!";
                
                $stmt_professor->execute([':professor_id' => $professor_id]);
                $professor_dados = $stmt_professor->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = "Erro ao fazer upload da foto.";
            }
        } else {
            $error = "Formato de arquivo não permitido. Use JPG, PNG ou GIF.";
        }
    } else {
        $error = "Selecione uma foto para enviar.";
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getGeneroTexto($genero) {
    if ($genero == 'M') return 'Masculino';
    if ($genero == 'F') return 'Feminino';
    return 'Não informado';
}

function getEstadoCivil($estado) {
    switch ($estado) {
        case 'solteiro': return 'Solteiro(a)';
        case 'casado': return 'Casado(a)';
        case 'divorciado': return 'Divorciado(a)';
        case 'viuvo': return 'Viúvo(a)';
        default: return 'Não informado';
    }
}

function getNacionalidade($nacionalidade) {
    if (empty($nacionalidade)) return 'Angolana';
    return $nacionalidade;
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
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .profile-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .profile-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            padding: 30px;
            text-align: center;
            color: white;
        }
        .profile-avatar {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid white;
            margin-bottom: 15px;
        }
        .profile-name {
            font-size: 1.5em;
            margin-bottom: 5px;
        }
        .profile-email {
            opacity: 0.9;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .info-title {
            font-size: 1.1em;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #006B3E;
        }
        .info-row {
            margin-bottom: 12px;
            display: flex;
        }
        .info-label {
            width: 130px;
            font-weight: bold;
            color: #666;
        }
        .info-value {
            flex: 1;
            color: #333;
        }
        .btn-primary-custom {
            background: #006B3E;
            border: none;
        }
        .btn-primary-custom:hover {
            background: #004d2d;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        .foto-preview {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #006B3E;
            margin-bottom: 15px;
        }
        input:disabled, textarea:disabled, select:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
            border-color: #dee2e6;
            color: #495057;
        }
        .badge-info {
            background: #e9ecef;
            color: #6c757d;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 10px;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <!-- INCLUIR O MENU CENTRALIZADO -->
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-user-circle"></i> Meu Perfil</h2>
                    <p>Visualize seus dados pessoais - Apenas a senha e a foto podem ser alteradas</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn">
                        <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Mensagens -->
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
            <!-- Coluna da Foto e Informações Básicas -->
            <div class="col-md-4">
                <div class="profile-card">
                    <div class="profile-header">
                        <?php 
                        $foto_path = '../../uploads/professores/fotos/' . ($professor_dados['foto'] ?? '');
                        if (!empty($professor_dados['foto']) && file_exists($foto_path)): ?>
                            <img src="<?php echo $foto_path; ?>" class="profile-avatar">
                        <?php else: ?>
                            <img src="../../assets/images/avatar-professor.png" class="profile-avatar">
                        <?php endif; ?>
                        <div class="profile-name"><?php echo htmlspecialchars($professor_dados['nome'] ?? ''); ?></div>
                        <div class="profile-email"><?php echo htmlspecialchars($professor_dados['email'] ?? ''); ?></div>
                    </div>
                    <div class="card-body text-center">
                        <button type="button" class="btn btn-primary btn-sm mb-2" data-bs-toggle="modal" data-bs-target="#modalFoto">
                            <i class="fas fa-camera"></i> Alterar Foto
                        </button>
                        <p class="text-muted small mt-2">
                            <i class="fas fa-calendar-alt"></i> Cadastrado em: <?php echo formatarData($professor_dados['created_at'] ?? date('Y-m-d')); ?>
                        </p>
                        <p class="text-muted small">
                            <i class="fas fa-id-badge"></i> ID: <?php echo $professor_id; ?>
                        </p>
                    </div>
                </div>
                
                <!-- Escola -->
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-building"></i> Instituição de Ensino
                        <span class="badge-info"><i class="fas fa-lock"></i> Apenas visualização</span>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Escola:</div>
                        <div class="info-value"><?php echo htmlspecialchars($escola['nome'] ?? 'Não definida'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Endereço:</div>
                        <div class="info-value"><?php echo htmlspecialchars($escola['endereco'] ?? 'Não informado'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Telefone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($escola['telefone'] ?? 'Não informado'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value"><?php echo htmlspecialchars($escola['email'] ?? 'Não informado'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Coluna de Dados Pessoais -->
            <div class="col-md-8">
                <!-- Dados Pessoais (Apenas visualização - campos desabilitados) -->
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-user"></i> Dados Pessoais
                        <span class="badge-info"><i class="fas fa-lock"></i> Apenas visualização</span>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Nome Completo</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($professor_dados['nome'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($professor_dados['email'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Telefone</label>
                            <input type="tel" class="form-control" value="<?php echo htmlspecialchars($professor_dados['telefone'] ?? 'Não informado'); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">BI / Documento</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($professor_dados['bi'] ?? 'Não informado'); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data de Nascimento</label>
                            <input type="text" class="form-control" value="<?php echo formatarData($professor_dados['data_nascimento'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Gênero</label>
                            <input type="text" class="form-control" value="<?php echo getGeneroTexto($professor_dados['genero'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Estado Civil</label>
                            <input type="text" class="form-control" value="<?php echo getEstadoCivil($professor_dados['estado_civil'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nacionalidade</label>
                            <input type="text" class="form-control" value="<?php echo getNacionalidade($professor_dados['nacionalidade'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Endereço Completo</label>
                            <textarea class="form-control" rows="2" disabled><?php echo htmlspecialchars($professor_dados['endereco'] ?? 'Não informado'); ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Informações Profissionais (Apenas visualização) -->
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-briefcase"></i> Informações Profissionais
                        <span class="badge-info"><i class="fas fa-lock"></i> Apenas visualização</span>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cargo</label>
                            <input type="text" class="form-control" value="Professor" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Regime de Trabalho</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($professor_dados['regime_trabalho'] ?? 'Período Integral'); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data de Admissão</label>
                            <input type="text" class="form-control" value="<?php echo formatarData($professor_dados['data_admissao'] ?? $professor_dados['created_at'] ?? 'Não informado'); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nº de Funcionário</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($professor_dados['numero_funcionario'] ?? $professor_id); ?>" disabled>
                        </div>
                    </div>
                </div>
                
                <!-- Estatísticas do Professor (Apenas visualização) -->
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-chart-line"></i> Estatísticas Acadêmicas
                        <span class="badge-info"><i class="fas fa-lock"></i> Apenas visualização</span>
                    </div>
                    <div class="row">
                        <div class="col-md-4 text-center">
                            <div class="stat-number" style="font-size: 28px; font-weight: bold; color: #006B3E;">
                                <?php
                                $sql_turmas = "SELECT COUNT(DISTINCT turma_id) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
                                $stmt = $conn->prepare($sql_turmas);
                                $stmt->execute([':professor_id' => $professor_id]);
                                echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                ?>
                            </div>
                            <small>Turmas</small>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="stat-number" style="font-size: 28px; font-weight: bold; color: #006B3E;">
                                <?php
                                $sql_disc = "SELECT COUNT(DISTINCT disciplina_id) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
                                $stmt = $conn->prepare($sql_disc);
                                $stmt->execute([':professor_id' => $professor_id]);
                                echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                ?>
                            </div>
                            <small>Disciplinas</small>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="stat-number" style="font-size: 28px; font-weight: bold; color: #006B3E;">
                                <?php
                                $sql_alunos = "SELECT COUNT(DISTINCT m.estudante_id) as total 
                                              FROM matriculas m
                                              INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = m.turma_id
                                              WHERE pdt.professor_id = :professor_id AND m.status = 'ativa'";
                                $stmt = $conn->prepare($sql_alunos);
                                $stmt->execute([':professor_id' => $professor_id]);
                                echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                ?>
                            </div>
                            <small>Alunos</small>
                        </div>
                    </div>
                </div>
                
                <!-- Alterar Senha (Única opção ativa) -->
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-lock"></i> Segurança - Alterar Senha
                        <span class="badge-info" style="background: #28a745; color: white;"><i class="fas fa-edit"></i> Permitido</span>
                    </div>
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Senha Atual</label>
                                <input type="password" name="senha_atual" class="form-control" placeholder="Digite sua senha atual" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nova Senha</label>
                                <input type="password" name="nova_senha" class="form-control" placeholder="Mínimo 6 caracteres" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Confirmar Nova Senha</label>
                                <input type="password" name="confirmar_senha" class="form-control" placeholder="Confirme a nova senha" required>
                            </div>
                            <div class="col-md-12">
                                <button type="submit" name="alterar_senha" class="btn btn-primary-custom">
                                    <i class="fas fa-key"></i> Alterar Senha
                                </button>
                            </div>
                        </div>
                    </form>
                    <hr>
                    <div class="alert alert-info small mb-0">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Importante:</strong> Apenas a senha e a foto podem ser alteradas por você. Para modificar outros dados, entre em contato com a administração da escola.
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Alterar Foto -->
    <div class="modal fade" id="modalFoto" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-camera"></i> Alterar Foto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body text-center">
                        <?php 
                        $foto_path = '../../uploads/professores/fotos/' . ($professor_dados['foto'] ?? '');
                        if (!empty($professor_dados['foto']) && file_exists($foto_path)): ?>
                            <img src="<?php echo $foto_path; ?>" class="foto-preview" id="previewFoto">
                        <?php else: ?>
                            <img src="../../assets/images/avatar-professor.png" class="foto-preview" id="previewFoto">
                        <?php endif; ?>
                        <div class="mt-3">
                            <input type="file" name="foto" class="form-control" accept="image/*" onchange="previewImagem(this)">
                            <small class="text-muted">Formatos permitidos: JPG, PNG, GIF. Tamanho máximo: 2MB</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="atualizar_foto" class="btn btn-primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function previewImagem(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewFoto').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html>