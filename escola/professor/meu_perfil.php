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
                $sql = "UPDATE funcionarios SET foto = :foto WHERE id = :professor_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':foto' => $nome_arquivo, ':professor_id' => $professor_id]);
                $success = "Foto atualizada com sucesso!";
                
                // Recarregar dados do professor
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Meu Perfil | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        /* ============================================
           CABEÇALHO DA PÁGINA
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-radius: 24px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .page-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.85;
            font-size: 0.85rem;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        /* ============================================
           PROFILE CARD
        ============================================ */
        .profile-card {
            background: white;
            border-radius: 24px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .profile-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.12);
        }

        .profile-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            padding: 30px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid white;
            margin-bottom: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            position: relative;
            z-index: 1;
        }

        .profile-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }

        .profile-email {
            opacity: 0.9;
            font-size: 0.85rem;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           INFO CARDS
        ============================================ */
        .info-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .info-title {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .info-title i {
            margin-right: 10px;
        }

        .badge-info {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .badge-edit {
            background: #28a745;
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .info-body {
            padding: 20px;
        }

        .info-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-label {
            width: 120px;
            font-weight: 600;
            color: #6c757d;
            font-size: 0.8rem;
        }

        .info-value {
            flex: 1;
            color: #333;
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* ============================================
           STATS CARDS
        ============================================ */
        .stats-grid {
            display: flex;
            justify-content: space-around;
            gap: 15px;
            margin-top: 10px;
        }

        .stat-item {
            text-align: center;
            flex: 1;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 16px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            background: #e9ecef;
            transform: translateY(-3px);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            color: #006B3E;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-top: 5px;
        }

        /* ============================================
           FORMULÁRIOS
        ============================================ */
        .form-label {
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-control {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        .form-control:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
            color: #6c757d;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert-custom {
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }

        .alert-success-custom {
            background: linear-gradient(135deg, #d4edda 0%, #c8e6c9 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger-custom {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-info-custom {
            background: linear-gradient(135deg, #cfe2ff 0%, #b8d4ff 100%);
            color: #084298;
            border-left: 4px solid #0d6efd;
        }

        /* ============================================
           MODAL
        ============================================ */
        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        .foto-preview {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #006B3E;
            margin-bottom: 15px;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .info-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .info-label {
                width: 100%;
            }
            
            .stats-grid {
                flex-direction: column;
                gap: 10px;
            }
            
            .profile-avatar {
                width: 100px;
                height: 100px;
            }
            
            .profile-name {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header fade-in">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-user-circle me-2"></i> Meu Perfil</h2>
                    <p>Visualize seus dados pessoais - Apenas a senha e a foto podem ser alteradas</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Mensagens -->
        <?php if ($success): ?>
            <div class="alert-custom alert-success-custom fade-in">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-custom alert-danger-custom fade-in">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Coluna Esquerda -->
            <div class="col-md-4">
                <!-- Profile Card -->
                <div class="profile-card fade-in">
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
                    <div class="card-body text-center p-4">
                        <button type="button" class="btn-primary-custom btn-sm" data-bs-toggle="modal" data-bs-target="#modalFoto">
                            <i class="fas fa-camera"></i> Alterar Foto
                        </button>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-calendar-alt"></i> Cadastrado em: <?php echo formatarData($professor_dados['created_at'] ?? date('Y-m-d')); ?>
                            </small>
                        </div>
                        <div>
                            <small class="text-muted">
                                <i class="fas fa-id-badge"></i> ID: <?php echo $professor_id; ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Instituição -->
                <div class="info-card fade-in">
                    <div class="info-title">
                        <div><i class="fas fa-building"></i> Instituição de Ensino</div>
                        <span class="badge-info"><i class="fas fa-lock"></i> Visualização</span>
                    </div>
                    <div class="info-body">
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
            </div>
            
            <!-- Coluna Direita -->
            <div class="col-md-8">
                <!-- Dados Pessoais -->
                <div class="info-card fade-in">
                    <div class="info-title">
                        <div><i class="fas fa-user"></i> Dados Pessoais</div>
                        <span class="badge-info"><i class="fas fa-lock"></i> Visualização</span>
                    </div>
                    <div class="info-body">
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
                </div>
                
                <!-- Informações Profissionais -->
                <div class="info-card fade-in">
                    <div class="info-title">
                        <div><i class="fas fa-briefcase"></i> Informações Profissionais</div>
                        <span class="badge-info"><i class="fas fa-lock"></i> Visualização</span>
                    </div>
                    <div class="info-body">
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
                </div>
                
                <!-- Estatísticas Acadêmicas -->
                <div class="info-card fade-in">
                    <div class="info-title">
                        <div><i class="fas fa-chart-line"></i> Estatísticas Acadêmicas</div>
                        <span class="badge-info"><i class="fas fa-lock"></i> Visualização</span>
                    </div>
                    <div class="info-body">
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-number">
                                    <?php
                                    $sql_turmas = "SELECT COUNT(DISTINCT turma_id) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
                                    $stmt = $conn->prepare($sql_turmas);
                                    $stmt->execute([':professor_id' => $professor_id]);
                                    echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                    ?>
                                </div>
                                <div class="stat-label">Turmas</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">
                                    <?php
                                    $sql_disc = "SELECT COUNT(DISTINCT disciplina_id) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
                                    $stmt = $conn->prepare($sql_disc);
                                    $stmt->execute([':professor_id' => $professor_id]);
                                    echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                    ?>
                                </div>
                                <div class="stat-label">Disciplinas</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">
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
                                <div class="stat-label">Alunos</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Alterar Senha -->
                <div class="info-card fade-in">
                    <div class="info-title">
                        <div><i class="fas fa-lock"></i> Segurança - Alterar Senha</div>
                        <span class="badge-edit"><i class="fas fa-edit"></i> Permitido</span>
                    </div>
                    <div class="info-body">
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
                                    <button type="submit" name="alterar_senha" class="btn-primary-custom">
                                        <i class="fas fa-key"></i> Alterar Senha
                                    </button>
                                </div>
                            </div>
                        </form>
                        <hr>
                        <div class="alert-info-custom p-3 rounded">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Importante:</strong> Apenas a senha e a foto podem ser alteradas por você. Para modificar outros dados, entre em contato com a administração da escola.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Alterar Foto -->
    <div class="modal fade" id="modalFoto" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-camera me-2"></i> Alterar Foto</h5>
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
                            <small class="text-muted mt-2 d-block">Formatos permitidos: JPG, PNG, GIF. Tamanho máximo: 2MB</small>
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
        
        // Animações ao scroll - Código corrigido
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fade-in');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            // Observar todos os elementos com a classe fade-in
            const fadeElements = document.querySelectorAll('.fade-in');
            fadeElements.forEach(element => {
                observer.observe(element);
            });
        });
    </script>
</body>
</html>