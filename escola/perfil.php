<?php
// escola/perfil.php - Perfil do Usuário (Professor/Admin) com Financeiro

require_once __DIR__ . '/../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_email = $_SESSION['usuario_email'] ?? '';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// ============================================
// DETECTAR TIPO DE USUÁRIO
// ============================================
$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');

// ============================================
// BUSCAR DADOS DO USUÁRIO
// ============================================
$sql_usuario = "SELECT u.* FROM usuarios u WHERE u.id = :usuario_id";
$stmt_usuario = $conn->prepare($sql_usuario);
$stmt_usuario->execute([':usuario_id' => $usuario_id]);
$usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DO FUNCIONÁRIO
// ============================================
$funcionario = null;
if ($is_professor || $is_admin) {
    $sql_funcionario = "
         SELECT f.*, 
               f.salario_base, f.salario_base,
               f.banco, f.conta_bancaria, f.iban, f.nif,
               f.carga_horaria_semanal, f.numero_funcionario, f.regime_trabalho,
                            f.valor_irrf, f.valor_inss, f.subsidio_alimentacao,
               f.subsidio_transporte, f.created_at, f.status
        FROM funcionarios f
        WHERE f.usuario_id = :usuario_id AND f.escola_id = :escola_id
    ";
    $stmt_funcionario = $conn->prepare($sql_funcionario);
    $stmt_funcionario->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
    $funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DÍVIDAS VENCIDAS COM DESCONTO EM FOLHA ATIVADO
// ============================================
$dividas_vencidas = [];
$sql_dividas_vencidas = "
   SELECT * FROM dividas 
    WHERE funcionario_id = :funcionario_id 
    AND status = 'pendente'
    AND desconto_folha = 1
    AND data_vencimento < CURDATE()
    AND processado_folha = 0
    ORDER BY data_vencimento ASC
";
$stmt = $conn->prepare($sql_dividas_vencidas);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$dividas_vencidas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DÍVIDAS A PAGAR DO FUNCIONÁRIO
// ============================================
$dividas_pagar = [];
$sql_dividas_pagar = "
    SELECT * FROM dividas 
    WHERE funcionario_id = :funcionario_id 
    AND tipo = 'pagar'
    AND status = 'pendente'
    ORDER BY data_vencimento ASC
";
$stmt = $conn->prepare($sql_dividas_pagar);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$dividas_pagar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DÍVIDAS A RECEBER
// ============================================
$dividas_receber = [];
$sql_dividas_receber = "
    SELECT * FROM dividas_a_receber 
    WHERE funcionario_id = :funcionario_id 
    AND status = 'pendente'
    ORDER BY data_vencimento ASC
";
$stmt = $conn->prepare($sql_dividas_receber);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$dividas_receber = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SALÁRIOS A RECEBER
// ============================================
$salarios_a_receber = [];
$sql_salarios_receber = "
    SELECT * FROM folha_processamento_funcionarios 
    WHERE funcionario_id = :funcionario_id 
    AND status = 'pendente'
    ORDER BY ano_competencia DESC, mes_competencia DESC
";
$stmt = $conn->prepare($sql_salarios_receber);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$salarios_a_receber = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SALÁRIOS RECEBIDOS
// ============================================
$salarios_recebidos = [];
$sql_salarios_recebidos = "
    SELECT * FROM folha_processamento_funcionarios 
    WHERE funcionario_id = :funcionario_id 
    AND status = 'pago'
    ORDER BY ano_competencia DESC, mes_competencia DESC
    LIMIT 12
";
$stmt = $conn->prepare($sql_salarios_recebidos);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$salarios_recebidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// VERIFICAR PROCESSAMENTO DA FOLHA
// ============================================
$processamento_folha = null;
$sql_processamento = "
    SELECT * FROM folha_processamento_funcionarios 
    WHERE funcionario_id = :funcionario_id 
    AND MONTH(data_processamento) = MONTH(CURDATE())
    AND YEAR(data_processamento) = YEAR(CURDATE())
    LIMIT 1
";
$stmt = $conn->prepare($sql_processamento);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$processamento_folha = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SOLICITAÇÕES DE VALE
// ============================================
$solicitacoes_vale = [];
$sql_vale = "
 SELECT * FROM solicitacoes_vale 
    WHERE funcionario_id = :funcionario_id 
    ORDER BY created_at DESC
    LIMIT 10
";
$stmt = $conn->prepare($sql_vale);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$solicitacoes_vale = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SOLICITAÇÕES DE FÉRIAS
// ============================================
$solicitacoes_ferias = [];
$sql_ferias = "
    SELECT * FROM solicitacoes_ferias 
    WHERE funcionario_id = :funcionario_id 
    ORDER BY created_at DESC
    LIMIT 10
";
$stmt = $conn->prepare($sql_ferias);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$solicitacoes_ferias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR SOLICITAÇÃO DE VALE
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_vale'])) {
    $valor = floatval(str_replace(',', '.', $_POST['valor'] ?? 0));
    $motivo = trim($_POST['motivo'] ?? '');
    $data_prevista_devolucao = $_POST['data_prevista_devolucao'] ?? '';
    $parcelas = (int)($_POST['parcelas'] ?? 1);
    $valor_parcela = $valor / $parcelas;
    
    if ($valor <= 0) {
        $error = "Valor inválido para solicitação de vale.";
    } elseif (empty($motivo)) {
        $error = "Informe o motivo da solicitação.";
    } elseif (empty($data_prevista_devolucao)) {
        $error = "Informe a data prevista para devolução.";
    } else {
        try {
            $conn->beginTransaction();
            
            $sql = "INSERT INTO solicitacoes_vale (funcionario_id, valor, motivo, data_prevista_devolucao, parcelas, valor_parcela, status, created_at) 
                    VALUES (:funcionario_id, :valor, :motivo, :data_prevista, :parcelas, :valor_parcela, 'pendente', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':funcionario_id' => $funcionario['id'],
                ':valor' => $valor,
                ':motivo' => $motivo,
                ':data_prevista' => $data_prevista_devolucao,
                ':parcelas' => $parcelas,
                ':valor_parcela' => $valor_parcela
            ]);
            
            if ($parcelas > 1) {
                $data_vencimento = new DateTime($data_prevista_devolucao);
                for ($i = 1; $i <= $parcelas; $i++) {
                    $sql_parcela = "INSERT INTO dividas (funcionario_id, tipo, valor, valor_original, parcela_atual, total_parcelas, data_vencimento, descricao, ativar_desconto_folha, status, created_at) VALUES (:funcionario_id, 'pagar', :valor_parcela, :valor_total, :num_parcela, :total_parcelas, :data_vencimento, :descricao, 1, 'pendente', NOW())";
                    $stmt_parcela = $conn->prepare($sql_parcela);
                    $stmt_parcela->execute([
                        ':funcionario_id' => $funcionario['id'],
                        ':valor_parcela' => $valor_parcela,
                        ':valor_total' => $valor,
                        ':num_parcela' => $i,
                        ':total_parcelas' => $parcelas,
                        ':data_vencimento' => $data_vencimento->format('Y-m-d'),
                        ':descricao' => "Vale solicitado - Parcela $i/$parcelas - $motivo"
                    ]);
                    $data_vencimento->modify('+1 month');
                }
            } else {
                $sql_divida = "INSERT INTO dividas (funcionario_id, tipo, valor, valor_original, parcela_atual, total_parcelas, data_vencimento, descricao, ativar_desconto_folha, status, created_at) VALUES (:funcionario_id, 'pagar', :valor, :valor, 1, 1, :data_vencimento, :descricao, 1, 'pendente', NOW())";
                $stmt_divida = $conn->prepare($sql_divida);
                $stmt_divida->execute([
                    ':funcionario_id' => $funcionario['id'],
                    ':valor' => $valor,
                    ':data_vencimento' => $data_prevista_devolucao,
                    ':descricao' => "Vale solicitado - $motivo"
                ]);
            }
            
            $conn->commit();
            $success = "Solicitação de vale enviada com sucesso! Aguarde a aprovação da administração.";
            
            $stmt = $conn->prepare($sql_vale);
            $stmt->execute([':funcionario_id' => $funcionario['id']]);
            $solicitacoes_vale = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Erro ao solicitar vale: " . $e->getMessage();
        }
    }
}

// ============================================
// PROCESSAR SOLICITAÇÃO DE FÉRIAS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_ferias'])) {
    $data_inicio = $_POST['data_inicio'] ?? '';
    $data_fim = $_POST['data_fim'] ?? '';
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    if (empty($data_inicio) || empty($data_fim)) {
        $error = "Informe o período das férias.";
    } elseif (strtotime($data_inicio) > strtotime($data_fim)) {
        $error = "Data de início não pode ser maior que data de fim.";
    } else {
        $dias_total = (strtotime($data_fim) - strtotime($data_inicio)) / (60 * 60 * 24) + 1;
        
        try {
            $sql = "INSERT INTO solicitacoes_ferias (funcionario_id, data_inicio, data_fim, dias_solicitados, observacoes, status, created_at) VALUES (:funcionario_id, :data_inicio, :data_fim, :dias, :observacoes, 'pendente', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':funcionario_id' => $funcionario['id'],
                ':data_inicio' => $data_inicio,
                ':data_fim' => $data_fim,
                ':dias' => $dias_total,
                ':observacoes' => $observacoes
            ]);
            $success = "Solicitação de férias enviada com sucesso! Aguarde a aprovação da administração.";
            
            $stmt = $conn->prepare($sql_ferias);
            $stmt->execute([':funcionario_id' => $funcionario['id']]);
            $solicitacoes_ferias = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error = "Erro ao solicitar férias: " . $e->getMessage();
        }
    }
}

// ============================================
// PROCESSAR ALTERAÇÃO DE SENHA
// ============================================
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
            $stmt_check->execute([':usuario_id' => $usuario_id]);
            $user = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($senha_atual, $user['senha'])) {
                $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $sql_update = "UPDATE usuarios SET senha = :senha, updated_at = NOW() WHERE id = :usuario_id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':senha' => $nova_senha_hash, ':usuario_id' => $usuario_id]);
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
            $diretorio = __DIR__ . '/../uploads/usuarios/fotos/';
            if (!file_exists($diretorio)) {
                mkdir($diretorio, 0777, true);
            }
            
            $nome_arquivo = 'usuario_' . $usuario_id . '_' . time() . '.' . $extensao;
            $caminho_completo = $diretorio . $nome_arquivo;
            
            if (move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
                $sql = "UPDATE usuarios SET foto = :foto WHERE id = :usuario_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':foto' => $nome_arquivo, ':usuario_id' => $usuario_id]);
                $success = "Foto atualizada com sucesso!";
                
                $sql_usuario = "SELECT * FROM usuarios WHERE id = :usuario_id";
                $stmt_usuario = $conn->prepare($sql_usuario);
                $stmt_usuario->execute([':usuario_id' => $usuario_id]);
                $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
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

function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pendente': return '<span class="badge bg-warning text-dark">Pendente</span>';
        case 'aprovado': return '<span class="badge bg-success">Aprovado</span>';
        case 'rejeitado': return '<span class="badge bg-danger">Rejeitado</span>';
        case 'pago': return '<span class="badge bg-info">Pago</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getMesTexto($mes) {
    $meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    return $meses[$mes] ?? '';
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

$dias_ferias_disponiveis = 22;
$sql_ferias_usadas = "SELECT SUM(dias_solicitados) as total FROM solicitacoes_ferias WHERE funcionario_id = :funcionario_id AND status = 'aprovado' AND YEAR(created_at) = YEAR(NOW())";
$stmt = $conn->prepare($sql_ferias_usadas);
$stmt->execute([':funcionario_id' => $funcionario['id'] ?? 0]);
$ferias_usadas = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$dias_disponiveis = $dias_ferias_disponiveis - $ferias_usadas;
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .profile-card { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .profile-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); padding: 30px; text-align: center; color: white; }
        .profile-avatar { width: 150px; height: 150px; object-fit: cover; border-radius: 50%; border: 4px solid white; margin-bottom: 15px; }
        .profile-name { font-size: 1.5em; margin-bottom: 5px; }
        .profile-email { opacity: 0.9; }
        .info-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .info-title { font-size: 1.1em; font-weight: bold; color: #006B3E; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #006B3E; }
        .salario-card { background: linear-gradient(135deg, #006B3E 0%, #28a745 100%); color: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .salario-valor { font-size: 2em; font-weight: bold; }
        .btn-primary-custom { background: #006B3E; border: none; }
        .btn-primary-custom:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .foto-preview { width: 150px; height: 150px; object-fit: cover; border-radius: 50%; border: 3px solid #006B3E; margin-bottom: 15px; }
        .tipo-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; margin-left: 10px; }
        .tipo-professor { background: #17a2b8; color: white; }
        .tipo-admin { background: #28a745; color: white; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        .divida-card { border-left: 4px solid; margin-bottom: 10px; }
        .divida-pagar { border-left-color: #dc3545; }
        .divida-receber { border-left-color: #28a745; }
        .divida-vencida { border-left-color: #ff6600; background-color: #fff3e6; }
        .salario-table th { background-color: #e9ecef; }
        .total-row { background-color: #f8f9fa; font-weight: bold; }
        
        .btn-ajuda { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text { position: absolute; right: 70px; background: #333; color: white; padding: 5px 10px; border-radius: 5px; font-size: 12px; white-space: nowrap; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) { .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; } .btn-ajuda i { font-size: 24px; } }
        
        .ajuda-section { margin-bottom: 20px; }
        .ajuda-section h5 { color: #006B3E; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 2px solid #006B3E; }
        .ajuda-section ul { padding-left: 20px; }
        .ajuda-section li { margin-bottom: 8px; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        .info-row { margin-bottom: 12px; display: flex; }
        .info-label { width: 130px; font-weight: bold; color: #666; }
        .info-value { flex: 1; color: #333; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-user-circle"></i> Meu Perfil</h2>
                    <p>Visualize seus dados pessoais e informações financeiras</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn"><i class="fas fa-arrow-left"></i> Voltar ao Dashboard</a>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <?php if ($processamento_folha): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> A folha de pagamento para este mês já foi processada. Novos descontos serão aplicados apenas no próximo mês.</div>
        <?php endif; ?>
        
        <?php if (!empty($dividas_vencidas)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-bell"></i> <strong>⚠️ Atenção!</strong> Você possui <?php echo count($dividas_vencidas); ?> dívida(s) vencida(s) com desconto em folha ativado.
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-4">
                <div class="profile-card">
                    <div class="profile-header">
                        <?php $foto_path = __DIR__ . '/../uploads/usuarios/fotos/' . ($usuario['foto'] ?? '');
                        if (!empty($usuario['foto']) && file_exists($foto_path)): ?>
                            <img src="../uploads/usuarios/fotos/<?php echo $usuario['foto']; ?>" class="profile-avatar">
                        <?php else: ?>
                            <img src="../assets/images/avatar-user.png" class="profile-avatar">
                        <?php endif; ?>
                        <div class="profile-name"><?php echo htmlspecialchars($usuario['nome'] ?? $usuario_nome); ?>
                            <?php if ($is_professor): ?><span class="tipo-badge tipo-professor"><i class="fas fa-chalkboard-user"></i> Professor</span>
                            <?php else: ?><span class="tipo-badge tipo-admin"><i class="fas fa-user-shield"></i> Administrador</span><?php endif; ?>
                        </div>
                        <div class="profile-email"><?php echo htmlspecialchars($usuario['email'] ?? $usuario_email); ?></div>
                    </div>
                    <div class="card-body text-center">
                        <button type="button" class="btn btn-primary btn-sm mb-2" data-bs-toggle="modal" data-bs-target="#modalFoto"><i class="fas fa-camera"></i> Alterar Foto</button>
                        <p class="text-muted small mt-2"><i class="fas fa-calendar-alt"></i> Cadastrado em: <?php echo formatarData($usuario['created_at'] ?? date('Y-m-d')); ?></p>
                        <p class="text-muted small"><i class="fas fa-id-badge"></i> ID: <?php echo $usuario_id; ?></p>
                    </div>
                </div>
                
                <?php if ($funcionario): ?>
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-university"></i> Dados Bancários</div>
                    <div class="info-row"><div class="info-label">Banco:</div><div class="info-value"><?php echo htmlspecialchars($funcionario['banco'] ?? 'Não informado'); ?></div></div>
                    <div class="info-row"><div class="info-label">Conta Bancária:</div><div class="info-value"><?php echo htmlspecialchars($funcionario['conta_bancaria'] ?? 'Não informado'); ?></div></div>
                    <div class="info-row"><div class="info-label">IBAN:</div><div class="info-value"><?php echo htmlspecialchars($funcionario['iban'] ?? 'Não informado'); ?></div></div>
                    <div class="info-row"><div class="info-label">NIF:</div><div class="info-value"><?php echo htmlspecialchars($funcionario['nif'] ?? 'Não informado'); ?></div></div>
                </div>
                <?php endif; ?>
                
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-building"></i> Instituição de Ensino</div>
                    <div class="info-row"><div class="info-label">Escola:</div><div class="info-value"><?php echo htmlspecialchars($escola['nome'] ?? 'Não definida'); ?></div></div>
                    <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value"><?php echo htmlspecialchars($escola['endereco'] ?? 'Não informado'); ?></div></div>
                    <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo htmlspecialchars($escola['telefone'] ?? 'Não informado'); ?></div></div>
                </div>
            </div>
            
            <div class="col-md-8">
                <?php if ($funcionario): ?>
                <div class="row">
                    <div class="col-md-6"><div class="salario-card"><i class="fas fa-money-bill-wave fa-2x mb-2"></i><h6>Salário Base</h6><div class="salario-valor"><?php echo formatarMoeda($funcionario['salario_base'] ?? 0); ?></div><small>Carga horária: <?php echo $funcionario['carga_horaria_semanal'] ?? '40'; ?>h/semana</small></div></div>
                    <div class="col-md-6"><div class="salario-card" style="background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);"><i class="fas fa-chart-line fa-2x mb-2"></i><h6>Salário Atual</h6><div class="salario-valor"><?php echo formatarMoeda($funcionario['salario_base'] ?? $funcionario['salario_base'] ?? 0); ?></div><small>Regime: <?php echo $funcionario['regime_trabalho'] ?? 'Período Integral'; ?></small></div></div>
                </div>
                <?php endif; ?>
                
                <div class="info-card">
                    <ul class="nav nav-tabs" id="perfilTab" role="tablist">
                        <li class="nav-item"><button class="nav-link active" id="dados-tab" data-bs-toggle="tab" data-bs-target="#dados" type="button"><i class="fas fa-user"></i> Dados Pessoais</button></li>
                        <li class="nav-item"><button class="nav-link" id="salarios-tab" data-bs-toggle="tab" data-bs-target="#salarios" type="button"><i class="fas fa-money-bill-alt"></i> Salários</button></li>
                        <li class="nav-item"><button class="nav-link" id="dividas-tab" data-bs-toggle="tab" data-bs-target="#dividas" type="button"><i class="fas fa-hand-holding-usd"></i> Dívidas</button></li>
                        <li class="nav-item"><button class="nav-link" id="vale-tab" data-bs-toggle="tab" data-bs-target="#vale" type="button"><i class="fas fa-hand-holding-heart"></i> Solicitar Vale</button></li>
                        <li class="nav-item"><button class="nav-link" id="ferias-tab" data-bs-toggle="tab" data-bs-target="#ferias" type="button"><i class="fas fa-umbrella-beach"></i> Solicitar Férias</button></li>
                        <li class="nav-item"><button class="nav-link" id="senha-tab" data-bs-toggle="tab" data-bs-target="#senha" type="button"><i class="fas fa-lock"></i> Segurança</button></li>
                    </ul>
                    
                    <div class="tab-content mt-3">
                        <!-- Dados Pessoais -->
                        <div class="tab-pane fade show active" id="dados">
                            <div class="row">
                                <div class="col-md-12 mb-3"><label class="form-label">Nome Completo</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($usuario['nome'] ?? $usuario_nome); ?>" disabled></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" class="form-control" value="<?php echo htmlspecialchars($usuario['email'] ?? $usuario_email); ?>" disabled></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Telefone</label><input type="tel" class="form-control" value="<?php echo htmlspecialchars($funcionario['telefone'] ?? 'Não informado'); ?>" disabled></div>
                                <?php if ($funcionario): ?>
                                <div class="col-md-6 mb-3"><label class="form-label">BI / Documento</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($funcionario['bi'] ?? 'Não informado'); ?>" disabled></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Data de Nascimento</label><input type="text" class="form-control" value="<?php echo formatarData($funcionario['data_nascimento'] ?? ''); ?>" disabled></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Gênero</label><input type="text" class="form-control" value="<?php echo getGeneroTexto($funcionario['genero'] ?? ''); ?>" disabled></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Estado Civil</label><input type="text" class="form-control" value="<?php echo getEstadoCivil($funcionario['estado_civil'] ?? ''); ?>" disabled></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Nacionalidade</label><input type="text" class="form-control" value="<?php echo getNacionalidade($funcionario['nacionalidade'] ?? ''); ?>" disabled></div>
                                <div class="col-md-12 mb-3"><label class="form-label">Endereço Completo</label><textarea class="form-control" rows="2" disabled><?php echo htmlspecialchars($funcionario['endereco'] ?? 'Não informado'); ?></textarea></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Cargo</label><input type="text" class="form-control" value="<?php echo $is_professor ? 'Professor' : 'Administrador'; ?>" disabled></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Data de Admissão</label><input type="text" class="form-control" value="<?php echo formatarData($funcionario['data_admissao'] ?? $funcionario['created_at'] ?? 'Não informado'); ?>" disabled></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Nº Funcionário</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($funcionario['numero_funcionario'] ?? $funcionario['id']); ?>" disabled></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Salários -->
                        <div class="tab-pane fade" id="salarios">
                            <h6 class="text-primary mb-3"><i class="fas fa-clock"></i> Salários a Receber</h6>
                            <?php if (empty($salarios_a_receber)): ?>
                                <div class="alert alert-success">Nenhum salário pendente.</div>
                            <?php else: ?>
                                <div class="table-responsive"><table class="table table-bordered salario-table"><thead><tr><th>Mês/Ano</th><th>Salário Líquido</th><th>Data</th><th>Status</th></tr></thead><tbody><?php foreach($salarios_a_receber as $s): ?><tr><td><?php echo getMesTexto($s['mes_referencia']) . '/' . $s['ano_referencia']; ?></td><td><strong><?php echo formatarMoeda($s['salario_liquido']); ?></strong></td><td><?php echo formatarData($s['data_processamento']); ?></td><td><?php echo getStatusBadge($s['status']); ?></td></tr><?php endforeach; ?></tbody></table></div>
                            <?php endif; ?>
                            <hr>
                            <h6 class="text-success mb-3"><i class="fas fa-history"></i> Histórico de Salários</h6>
                            <?php if (empty($salarios_recebidos)): ?>
                                <div class="alert alert-secondary">Nenhum registro encontrado.</div>
                            <?php else: ?>
                                <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Mês/Ano</th><th>Salário Líquido</th><th>Data Pagamento</th></tr></thead><tbody><?php foreach($salarios_recebidos as $s): ?><tr><td><?php echo getMesTexto($s['mes_referencia']) . '/' . $s['ano_referencia']; ?></td><td><strong><?php echo formatarMoeda($s['salario_liquido']); ?></strong></td><td><?php echo formatarData($s['data_pagamento'] ?? $s['data_processamento']); ?></td></tr><?php endforeach; ?></tbody></table></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Dívidas -->
                        <div class="tab-pane fade" id="dividas">
                            <?php if (!empty($dividas_vencidas)): ?>
                                <h6 class="text-danger mb-3">Dívidas Vencidas</h6>
                                <?php foreach($dividas_vencidas as $d): ?><div class="divida-card divida-vencida p-3 mb-2 rounded"><div class="row"><div class="col-md-4"><strong>Valor:</strong> <?php echo formatarMoeda($d['valor']); ?></div><div class="col-md-4"><strong>Vencimento:</strong> <?php echo formatarData($d['data_vencimento']); ?></div><div class="col-md-12 mt-2"><strong>Descrição:</strong> <?php echo htmlspecialchars($d['descricao'] ?? ''); ?></div></div></div><?php endforeach; ?>
                                <hr>
                            <?php endif; ?>
                            <h6 class="text-danger mb-3">Dívidas a Pagar</h6>
                            <?php if(empty($dividas_pagar)): ?><div class="alert alert-info">Nenhuma dívida a pagar.</div><?php else: ?><?php foreach($dividas_pagar as $d): ?><div class="divida-card divida-pagar p-3 mb-2 bg-light rounded"><div class="row"><div class="col-md-4"><strong>Valor:</strong> <?php echo formatarMoeda($d['valor']); ?></div><div class="col-md-4"><strong>Vencimento:</strong> <?php echo formatarData($d['data_vencimento']); ?></div><div class="col-md-4"><strong>Status:</strong> <?php echo getStatusBadge($d['status']); ?></div><div class="col-md-12 mt-2"><strong>Descrição:</strong> <?php echo htmlspecialchars($d['descricao'] ?? ''); ?></div></div></div><?php endforeach; ?><?php endif; ?>
                            <hr>
                            <h6 class="text-success mb-3">Dívidas a Receber</h6>
                            <?php if(empty($dividas_receber)): ?><div class="alert alert-info">Nenhuma dívida a receber.</div><?php else: ?><?php foreach($dividas_receber as $d): ?><div class="divida-card divida-receber p-3 mb-2 bg-light rounded"><div class="row"><div class="col-md-4"><strong>Valor:</strong> <?php echo formatarMoeda($d['valor']); ?></div><div class="col-md-4"><strong>Vencimento:</strong> <?php echo formatarData($d['data_vencimento']); ?></div><div class="col-md-4"><strong>Devedor:</strong> <?php echo htmlspecialchars($d['devedor_nome'] ?? ''); ?></div></div></div><?php endforeach; ?><?php endif; ?>
                        </div>
                        
                        <!-- Solicitar Vale -->
                        <div class="tab-pane fade" id="vale">
                            <form method="POST">
                                <div class="mb-3"><label class="form-label">Valor do Vale (Kz)</label><input type="number" step="0.01" name="valor" class="form-control" required placeholder="Ex: 50000" min="1000" max="<?php echo ($funcionario['salario_base'] ?? 0) * 0.5; ?>"><small class="text-muted">Máximo: 50% do salário (<?php echo formatarMoeda(($funcionario['salario_base'] ?? 0) * 0.5); ?>)</small></div>
                                <div class="mb-3"><label class="form-label">Número de Parcelas</label><select name="parcelas" class="form-control" id="parcelas" onchange="calcularValorParcela()"><option value="1">À vista</option><option value="2">2 parcelas</option><option value="3">3 parcelas</option><option value="4">4 parcelas</option><option value="5">5 parcelas</option><option value="6">6 parcelas</option></select></div>
                                <div class="mb-3"><label class="form-label">Valor por Parcela</label><input type="text" class="form-control" id="valor_parcela" readonly disabled></div>
                                <div class="mb-3"><label class="form-label">Motivo</label><textarea name="motivo" class="form-control" rows="3" required></textarea></div>
                                <div class="mb-3"><label class="form-label">Data Primeira Devolução</label><input type="date" name="data_prevista_devolucao" class="form-control" required min="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" max="<?php echo date('Y-m-d', strtotime('+12 months')); ?>"></div>
                                <button type="submit" name="solicitar_vale" class="btn btn-primary-custom"><i class="fas fa-paper-plane"></i> Solicitar Vale</button>
                            </form>
                            <hr>
                            <h6>Histórico</h6>
                            <?php if(empty($solicitacoes_vale)): ?><div class="alert alert-secondary">Nenhuma solicitação.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Data</th><th>Valor</th><th>Parcelas</th><th>Status</th></tr></thead><tbody><?php foreach($solicitacoes_vale as $s): ?><tr><td><?php echo formatarData($s['created_at']); ?></td><td><?php echo formatarMoeda($s['valor']); ?></td><td><?php echo $s['parcelas'] > 1 ? $s['parcelas'] . 'x' : 'À vista'; ?></td><td><?php echo getStatusBadge($s['status']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                        </div>
                        
                        <!-- Solicitar Férias -->
                        <div class="tab-pane fade" id="ferias">
                            <div class="alert alert-info"><i class="fas fa-calendar-alt"></i> Dias de férias disponíveis: <strong><?php echo $dias_disponiveis; ?></strong> dias (Total: 22 | Utilizados: <?php echo $ferias_usadas; ?>)</div>
                            <form method="POST">
                                <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Data Início</label><input type="date" name="data_inicio" class="form-control" required min="<?php echo date('Y-m-d', strtotime('+15 days')); ?>"></div>
                                <div class="col-md-6 mb-3"><label class="form-label">Data Fim</label><input type="date" name="data_fim" class="form-control" required></div>
                                <div class="col-md-12 mb-3"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control" rows="3"></textarea></div>
                                <div class="col-md-12"><button type="submit" name="solicitar_ferias" class="btn btn-primary-custom"><i class="fas fa-paper-plane"></i> Solicitar Férias</button></div></div>
                            </form>
                            <hr>
                            <h6>Histórico</h6>
                            <?php if(empty($solicitacoes_ferias)): ?><div class="alert alert-secondary">Nenhuma solicitação.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Data</th><th>Período</th><th>Dias</th><th>Status</th></tr></thead><tbody><?php foreach($solicitacoes_ferias as $s): ?><tr><td><?php echo formatarData($s['created_at']); ?></td><td><?php echo formatarData($s['data_inicio']) . ' a ' . formatarData($s['data_fim']); ?></td><td><?php echo $s['dias_solicitados']; ?></td><td><?php echo getStatusBadge($s['status']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                        </div>
                        
                        <!-- Segurança -->
                        <div class="tab-pane fade" id="senha">
                            <form method="POST">
                                <div class="mb-3"><label class="form-label">Senha Atual</label><input type="password" name="senha_atual" class="form-control" required></div>
                                <div class="mb-3"><label class="form-label">Nova Senha</label><input type="password" name="nova_senha" class="form-control" required></div>
                                <div class="mb-3"><label class="form-label">Confirmar Nova Senha</label><input type="password" name="confirmar_senha" class="form-control" required></div>
                                <button type="submit" name="alterar_senha" class="btn btn-primary-custom"><i class="fas fa-key"></i> Alterar Senha</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Foto -->
    <div class="modal fade" id="modalFoto" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-camera"></i> Alterar Foto</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST" enctype="multipart/form-data"><div class="modal-body text-center"><img src="../assets/images/avatar-user.png" class="foto-preview" id="previewFoto"><div class="mt-3"><input type="file" name="foto" class="form-control" accept="image/*" onchange="previewImagem(this)"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="atualizar_foto" class="btn btn-primary">Salvar</button></div></form>
            </div>
        </div>
    </div>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Meu Perfil</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="ajuda-section"><h5><i class="fas fa-id-card"></i> Sobre o Meu Perfil</h5><p>Centraliza todas as suas informações pessoais, profissionais e financeiras.</p></div>
                    <div class="ajuda-section"><h5><i class="fas fa-user"></i> Seções disponíveis:</h5><ul><li><strong>Dados Pessoais:</strong> Nome, email, telefone, BI, data de nascimento</li><li><strong>Dados Bancários:</strong> Banco, conta, IBAN, NIF</li><li><strong>Salários:</strong> Salário base, atual, subsídios, histórico</li><li><strong>Dívidas:</strong> A pagar, a receber e vencidas</li><li><strong>Solicitar Vale:</strong> Adiantamento salarial (máx 50% do salário)</li><li><strong>Solicitar Férias:</strong> Período de férias (min 5 dias, máx 22 dias/ano)</li><li><strong>Segurança:</strong> Alteração de senha</li></ul></div>
                    <div class="ajuda-section"><h5><i class="fas fa-lock"></i> Importante:</h5><ul><li>Apenas senha e foto podem ser alteradas por você</li><li>Demais dados devem ser atualizados pela administração</li><li>Solicitações de vale e férias requerem aprovação</li></ul></div>
                    <div class="alert alert-info mt-3"><i class="fas fa-info-circle"></i> <strong>Dúvidas sobre valores?</strong> Entre em contato com o departamento financeiro da escola.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><a href="suporte/faq.php" class="btn btn-primary-custom"><i class="fas fa-book"></i> Ver FAQ</a><a href="suporte/chamados.php" class="btn btn-info"><i class="fas fa-headset"></i> Abrir Chamado</a></div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function previewImagem(input) { if(input.files && input.files[0]){ var reader = new FileReader(); reader.onload = function(e){ document.getElementById('previewFoto').src = e.target.result; }; reader.readAsDataURL(input.files[0]); } }
        function calcularValorParcela() { let valor = parseFloat(document.querySelector('input[name="valor"]')?.value); let parcelas = parseInt(document.querySelector('#parcelas')?.value); if(!isNaN(valor) && valor > 0 && parcelas > 0){ document.getElementById('valor_parcela').value = (valor / parcelas).toFixed(2) + ' Kz'; } else { document.getElementById('valor_parcela').value = ''; } }
        document.querySelector('input[name="valor"]')?.addEventListener('input', calcularValorParcela);
        document.querySelector('#parcelas')?.addEventListener('change', calcularValorParcela);
        document.getElementById('menuToggle')?.addEventListener('click', function() { document.querySelector('.sidebar')?.classList.toggle('active'); document.querySelector('.main-content')?.classList.toggle('active'); });
        document.getElementById('btnAjuda')?.addEventListener('click', function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
        calcularValorParcela();
    </script>
</body>
</html>