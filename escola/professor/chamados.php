<?php
// escola/suporte/chamados.php - Sistema de Chamados de Suporte

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
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

$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_suporte = ($usuario_tipo == 'suporte' || $papel == 'suporte');

// Buscar funcionário
$funcionario_id = null;
$sql_funcionario = "SELECT id FROM funcionarios WHERE usuario_id = :usuario_id AND escola_id = :escola_id";
$stmt_funcionario = $conn->prepare($sql_funcionario);
$stmt_funcionario->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
$funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario['id'] ?? null;

// Filtros
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todas';
$prioridade_filtro = isset($_GET['prioridade']) ? $_GET['prioridade'] : 'todas';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$success = '';
$error = '';

// Criar chamado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['criar_chamado'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'outro';
    $prioridade = $_POST['prioridade'] ?? 'media';
    
    if (empty($titulo)) {
        $error = "O título do chamado é obrigatório.";
    } elseif (empty($descricao)) {
        $error = "A descrição do chamado é obrigatória.";
    } else {
        try {
            $numero_chamado = 'CHAM-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $sql = "INSERT INTO chamados_suporte (numero_chamado, escola_id, funcionario_id, usuario_id, titulo, descricao, categoria, prioridade, status, data_abertura, created_at) VALUES (:numero, :escola_id, :funcionario_id, :usuario_id, :titulo, :descricao, :categoria, :prioridade, 'aberto', NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':numero' => $numero_chamado,
                ':escola_id' => $escola_id,
                ':funcionario_id' => $funcionario_id,
                ':usuario_id' => $usuario_id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':categoria' => $categoria,
                ':prioridade' => $prioridade
            ]);
            $success = "Chamado #$numero_chamado criado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao criar chamado: " . $e->getMessage();
        }
    }
}

// Responder chamado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['responder_chamado'])) {
    $chamado_id = (int)$_POST['chamado_id'];
    $mensagem = trim($_POST['mensagem'] ?? '');
    $anexo = null;
    
    if (empty($mensagem)) {
        $error = "A mensagem de resposta é obrigatória.";
    } else {
        try {
            if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] == 0) {
                $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'txt'];
                $extensao = strtolower(pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION));
                if (in_array($extensao, $extensoes_permitidas)) {
                    $diretorio = __DIR__ . '/../../uploads/chamados/';
                    if (!file_exists($diretorio)) mkdir($diretorio, 0777, true);
                    $nome_arquivo = 'chamado_' . $chamado_id . '_' . time() . '.' . $extensao;
                    if (move_uploaded_file($_FILES['anexo']['tmp_name'], $diretorio . $nome_arquivo)) {
                        $anexo = $nome_arquivo;
                    }
                }
            }
            
            $sql = "INSERT INTO chamados_respostas (chamado_id, usuario_id, mensagem, anexo, data_resposta) VALUES (:chamado_id, :usuario_id, :mensagem, :anexo, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':chamado_id' => $chamado_id, ':usuario_id' => $usuario_id, ':mensagem' => $mensagem, ':anexo' => $anexo]);
            
            if (isset($_POST['fechar_chamado']) && $_POST['fechar_chamado'] == '1') {
                $sql_update = "UPDATE chamados_suporte SET status = 'fechado', data_fechamento = NOW() WHERE id = :id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':id' => $chamado_id]);
                $success = "Chamado respondido e fechado com sucesso!";
            } else {
                $sql_update = "UPDATE chamados_suporte SET status = 'em_andamento', updated_at = NOW() WHERE id = :id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':id' => $chamado_id]);
                $success = "Resposta adicionada com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao responder chamado: " . $e->getMessage();
        }
    }
}

// Buscar chamados
$where_conditions = [];
$params = [];

if (!$is_admin && !$is_suporte) {
    $where_conditions[] = "c.funcionario_id = :funcionario_id";
    $params[':funcionario_id'] = $funcionario_id;
}

if ($status_filtro != 'todos') { $where_conditions[] = "c.status = :status"; $params[':status'] = $status_filtro; }
if ($categoria_filtro != 'todas') { $where_conditions[] = "c.categoria = :categoria"; $params[':categoria'] = $categoria_filtro; }
if ($prioridade_filtro != 'todas') { $where_conditions[] = "c.prioridade = :prioridade"; $params[':prioridade'] = $prioridade_filtro; }
if (!empty($busca)) { $where_conditions[] = "(c.titulo LIKE :busca OR c.descricao LIKE :busca OR c.numero_chamado LIKE :busca)"; $params[':busca'] = "%$busca%"; }

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql_chamados = "SELECT c.*, u.nome as usuario_nome, (SELECT COUNT(*) FROM chamados_respostas WHERE chamado_id = c.id) as total_respostas FROM chamados_suporte c LEFT JOIN usuarios u ON u.id = c.usuario_id $where_sql ORDER BY CASE c.prioridade WHEN 'alta' THEN 1 WHEN 'media' THEN 2 WHEN 'baixa' THEN 3 END, c.data_abertura DESC";
$stmt_chamados = $conn->prepare($sql_chamados);
$stmt_chamados->execute($params);
$chamados = $stmt_chamados->fetchAll(PDO::FETCH_ASSOC);

// Detalhe do chamado
$chamado_detalhe = null;
$respostas = [];
$chamado_id_detalhe = isset($_GET['ver']) ? (int)$_GET['ver'] : (isset($_POST['chamado_id']) ? (int)$_POST['chamado_id'] : 0);

if ($chamado_id_detalhe > 0) {
    $sql_detalhe = "SELECT c.*, u.nome as usuario_nome FROM chamados_suporte c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = :id";
    $stmt_detalhe = $conn->prepare($sql_detalhe);
    $stmt_detalhe->execute([':id' => $chamado_id_detalhe]);
    $chamado_detalhe = $stmt_detalhe->fetch(PDO::FETCH_ASSOC);
    if ($chamado_detalhe) {
        $sql_respostas = "SELECT r.*, u.nome as usuario_nome FROM chamados_respostas r LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.chamado_id = :chamado_id ORDER BY r.data_resposta ASC";
        $stmt_respostas = $conn->prepare($sql_respostas);
        $stmt_respostas->execute([':chamado_id' => $chamado_id_detalhe]);
        $respostas = $stmt_respostas->fetchAll(PDO::FETCH_ASSOC);
    }
}

function getStatusChamado($status){
    switch($status){
        case 'aberto': return '<span class="badge badge-aberto"><i class="fas fa-circle"></i> Aberto</span>';
        case 'em_andamento': return '<span class="badge badge-andamento"><i class="fas fa-spinner"></i> Em Andamento</span>';
        case 'respondido': return '<span class="badge badge-respondido"><i class="fas fa-reply"></i> Respondido</span>';
        case 'fechado': return '<span class="badge badge-fechado"><i class="fas fa-check"></i> Fechado</span>';
        default: return '<span class="badge bg-secondary">'.$status.'</span>';
    }
}

function getPrioridadeBadge($prioridade){
    switch($prioridade){
        case 'alta': return '<span class="badge badge-alta"><i class="fas fa-arrow-up"></i> Alta</span>';
        case 'media': return '<span class="badge badge-media"><i class="fas fa-minus"></i> Média</span>';
        case 'baixa': return '<span class="badge badge-baixa"><i class="fas fa-arrow-down"></i> Baixa</span>';
        default: return '<span class="badge bg-secondary">'.$prioridade.'</span>';
    }
}

function getCategoriaIcone($categoria){
    switch($categoria){
        case 'tecnico': return '<i class="fas fa-laptop-code"></i>';
        case 'administrativo': return '<i class="fas fa-file-alt"></i>';
        case 'financeiro': return '<i class="fas fa-money-bill-wave"></i>';
        case 'academico': return '<i class="fas fa-graduation-cap"></i>';
        default: return '<i class="fas fa-question-circle"></i>';
    }
}

function getCategoriaTexto($categoria){
    switch($categoria){
        case 'tecnico': return 'Técnico';
        case 'administrativo': return 'Administrativo';
        case 'financeiro': return 'Financeiro';
        case 'academico': return 'Acadêmico';
        default: return 'Outro';
    }
}

function formatarDataHora($data) { return $data ? date('d/m/Y H:i', strtotime($data)) : '-'; }
function timeAgo($datetime) { 
    $diff = time() - strtotime($datetime); 
    if($diff<60) return 'agora'; 
    if($diff<3600) return round($diff/60).' min'; 
    if($diff<86400) return round($diff/3600).'h'; 
    if($diff<604800) return round($diff/86400).'d'; 
    return date('d/m/Y', strtotime($datetime)); 
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Chamados de Suporte | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E VARIÁVEIS
        ============================================ */
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --orange: #fd7e14;
            --purple: #6f42c1;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 15px 50px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
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
           SIDEBAR
        ============================================ */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: var(--primary-gradient);
            color: white;
            transition: var(--transition);
            z-index: 1000;
            overflow-y: auto;
        }

        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.active {
                left: 0;
            }
        }

        .menu-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--primary-green);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            cursor: pointer;
            display: none;
            transition: var(--transition);
        }

        .menu-toggle:hover {
            transform: scale(1.05);
            background: var(--primary-dark);
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
        }

        /* ============================================
           PAGE HEADER
        ============================================ */
        .page-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '🎫';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 120px;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-primary-custom {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
            color: white;
        }

        /* ============================================
           CARDS
        ============================================ */
        .card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            margin-bottom: 25px;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: var(--primary-dark);
            border-radius: 20px 20px 0 0 !important;
            padding: 18px 24px;
            font-weight: 700;
            border-bottom: 2px solid var(--primary-green);
        }

        /* ============================================
           FILTER CARD
        ============================================ */
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }

        .filter-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            transition: var(--transition);
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
        }

        /* ============================================
           CHAMADO CARD
        ============================================ */
        .chamado-card {
            background: white;
            border-radius: 20px;
            padding: 0;
            overflow: hidden;
            cursor: pointer;
            transition: var(--transition);
            height: 100%;
        }

        .chamado-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--card-shadow-hover);
        }

        .chamado-card.alta { border-top: 4px solid var(--danger); }
        .chamado-card.media { border-top: 4px solid var(--warning); }
        .chamado-card.baixa { border-top: 4px solid var(--success); }

        .chamado-body {
            padding: 20px;
        }

        .chamado-titulo {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #212529;
        }

        .chamado-desc {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .chamado-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e9ecef;
        }

        /* ============================================
           BADGES
        ============================================ */
        .badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-aberto { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .badge-andamento { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-respondido { background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%); color: white; }
        .badge-fechado { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .badge-alta { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .badge-media { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-baixa { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }

        /* ============================================
           RESPOSTA ITEM
        ============================================ */
        .resposta-item {
            background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%);
            border-left: 4px solid var(--primary-green);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 15px;
            transition: var(--transition);
        }

        .resposta-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .resposta-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e9ecef;
        }

        /* ============================================
           MODAL
        ============================================ */
        .modal-header-custom {
            background: var(--primary-gradient);
            color: white;
        }

        .modal-header-custom .btn-close-white {
            filter: brightness(0) invert(1);
        }

        /* ============================================
           BOTÃO AJUDA
        ============================================ */
        .btn-ajuda {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            border: none;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            z-index: 1000;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.3);
        }

        .btn-ajuda i {
            font-size: 28px;
        }

        .btn-ajuda .tooltip-text {
            position: absolute;
            right: 75px;
            background: #212529;
            color: white;
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 0.8rem;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            font-weight: 500;
        }

        .btn-ajuda:hover .tooltip-text {
            opacity: 1;
        }

        @media (max-width: 768px) {
            .btn-ajuda {
                bottom: 20px;
                right: 20px;
                width: 50px;
                height: 50px;
            }
            .btn-ajuda i {
                font-size: 24px;
            }
        }

        /* ============================================
           HELP SECTION
        ============================================ */
        .ajuda-section {
            margin-bottom: 25px;
        }

        .ajuda-section h5 {
            color: var(--primary-green);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-green);
            font-weight: 700;
        }

        .ajuda-section ul, .ajuda-section ol {
            padding-left: 20px;
        }

        .ajuda-section li {
            margin-bottom: 8px;
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

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }

        /* ============================================
           SCROLLBAR
        ============================================ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .chamado-titulo {
                font-size: 0.9rem;
            }
            
            .btn-ajuda .tooltip-text {
                display: none;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-ajuda, .filter-card, .menu-toggle, .sidebar {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .page-header {
                background: #006B3E !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .chamado-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
   
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-headset me-2"></i> Chamados de Suporte</h2>
                    <p>Solicite assistência técnica e administrativa para sua instituição</p>
                </div>
                <div class="no-print">
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalNovoChamado" style="background: white; color: var(--primary-green); border-radius: 50px; padding: 10px 24px; font-weight: 600;">
                        <i class="fas fa-plus me-2"></i> Novo Chamado
                    </button>
                    <a href="../dashboard.php" class="btn-voltar ms-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-card fade-in-up">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="filter-label"><i class="fas fa-filter"></i> Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro=='todos'?'selected':''; ?>>Todos</option>
                        <option value="aberto" <?php echo $status_filtro=='aberto'?'selected':''; ?>>Aberto</option>
                        <option value="em_andamento" <?php echo $status_filtro=='em_andamento'?'selected':''; ?>>Em Andamento</option>
                        <option value="respondido" <?php echo $status_filtro=='respondido'?'selected':''; ?>>Respondido</option>
                        <option value="fechado" <?php echo $status_filtro=='fechado'?'selected':''; ?>>Fechado</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="filter-label"><i class="fas fa-tag"></i> Categoria</label>
                    <select name="categoria" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?php echo $categoria_filtro=='todas'?'selected':''; ?>>Todas</option>
                        <option value="tecnico" <?php echo $categoria_filtro=='tecnico'?'selected':''; ?>>🖥️ Técnico</option>
                        <option value="administrativo" <?php echo $categoria_filtro=='administrativo'?'selected':''; ?>>📄 Administrativo</option>
                        <option value="financeiro" <?php echo $categoria_filtro=='financeiro'?'selected':''; ?>>💰 Financeiro</option>
                        <option value="academico" <?php echo $categoria_filtro=='academico'?'selected':''; ?>>🎓 Acadêmico</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="filter-label"><i class="fas fa-flag"></i> Prioridade</label>
                    <select name="prioridade" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?php echo $prioridade_filtro=='todas'?'selected':''; ?>>Todas</option>
                        <option value="alta" <?php echo $prioridade_filtro=='alta'?'selected':''; ?>>🔴 Alta</option>
                        <option value="media" <?php echo $prioridade_filtro=='media'?'selected':''; ?>>🟡 Média</option>
                        <option value="baixa" <?php echo $prioridade_filtro=='baixa'?'selected':''; ?>>🟢 Baixa</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="filter-label"><i class="fas fa-search"></i> Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busca" class="form-control" placeholder="Título, número..." value="<?php echo htmlspecialchars($busca); ?>">
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Lista de Chamados -->
        <?php if (empty($chamados)): ?>
            <div class="card fade-in-up">
                <div class="card-body text-center py-5">
                    <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                    <h4>Nenhum chamado encontrado</h4>
                    <p class="text-muted">Nenhum chamado corresponde aos filtros selecionados.</p>
                    <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalNovoChamado">
                        <i class="fas fa-plus"></i> Abrir Chamado
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($chamados as $index => $chamado): ?>
                <div class="col-md-6 col-lg-4 mb-4 fade-in-up" style="animation-delay: <?php echo ($index % 6) * 0.05; ?>s">
                    <div class="chamado-card <?php echo $chamado['prioridade']; ?>" onclick="verChamado(<?php echo $chamado['id']; ?>)">
                        <div class="chamado-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="text-muted small">
                                    <?php echo getCategoriaIcone($chamado['categoria']); ?> 
                                    <span class="ms-1"><?php echo getCategoriaTexto($chamado['categoria']); ?></span>
                                </div>
                                <?php echo getPrioridadeBadge($chamado['prioridade']); ?>
                            </div>
                            <h6 class="chamado-titulo"><?php echo htmlspecialchars($chamado['titulo']); ?></h6>
                            <small class="text-muted">#<?php echo htmlspecialchars($chamado['numero_chamado']); ?></small>
                            <p class="chamado-desc mt-2"><?php echo htmlspecialchars(substr($chamado['descricao'], 0, 80)) . '...'; ?></p>
                            <div class="chamado-stats">
                                <div><?php echo getStatusChamado($chamado['status']); ?></div>
                                <div class="text-end">
                                    <small class="text-muted">
                                        <i class="fas fa-clock"></i> <?php echo timeAgo($chamado['data_abertura']); ?>
                                    </small>
                                    <?php if($chamado['total_respostas'] > 0): ?>
                                    <br><small><i class="fas fa-comment"></i> <?php echo $chamado['total_respostas']; ?> respostas</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Novo Chamado -->
    <div class="modal fade" id="modalNovoChamado" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Abrir Novo Chamado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Título *</label>
                            <input type="text" name="titulo" class="form-control" placeholder="Ex: Problemas com impressora" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Categoria</label>
                                <select name="categoria" class="form-select">
                                    <option value="tecnico">🖥️ Técnico</option>
                                    <option value="administrativo">📄 Administrativo</option>
                                    <option value="financeiro">💰 Financeiro</option>
                                    <option value="academico">🎓 Acadêmico</option>
                                    <option value="outro">❓ Outro</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Prioridade</label>
                                <select name="prioridade" class="form-select">
                                    <option value="baixa">🟢 Baixa</option>
                                    <option value="media" selected>🟡 Média</option>
                                    <option value="alta">🔴 Alta</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descrição *</label>
                            <textarea name="descricao" class="form-control" rows="5" placeholder="Descreva detalhadamente o problema..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="criar_chamado" class="btn btn-primary-custom">
                            <i class="fas fa-paper-plane"></i> Enviar Chamado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Visualizar Chamado -->
    <?php if ($chamado_detalhe): ?>
    <div class="modal fade" id="modalVerChamado" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title">Chamado #<?php echo htmlspecialchars($chamado_detalhe['numero_chamado']); ?> - <?php echo htmlspecialchars($chamado_detalhe['titulo']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="fecharModal()"></button>
                </div>
                <div class="modal-body">
                    <!-- Informações do Chamado -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-2">
                            <strong>Status:</strong><br>
                            <?php echo getStatusChamado($chamado_detalhe['status']); ?>
                        </div>
                        <div class="col-md-3 mb-2">
                            <strong>Prioridade:</strong><br>
                            <?php echo getPrioridadeBadge($chamado_detalhe['prioridade']); ?>
                        </div>
                        <div class="col-md-3 mb-2">
                            <strong>Categoria:</strong><br>
                            <?php echo getCategoriaIcone($chamado_detalhe['categoria']); ?> <?php echo getCategoriaTexto($chamado_detalhe['categoria']); ?>
                        </div>
                        <div class="col-md-3 mb-2">
                            <strong>Aberto em:</strong><br>
                            <?php echo formatarDataHora($chamado_detalhe['data_abertura']); ?>
                        </div>
                    </div>
                    
                    <!-- Descrição -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <strong><i class="fas fa-info-circle me-2"></i> Descrição do Problema</strong>
                        </div>
                        <div class="card-body">
                            <p><?php echo nl2br(htmlspecialchars($chamado_detalhe['descricao'])); ?></p>
                            <small class="text-muted">Aberto por: <?php echo htmlspecialchars($chamado_detalhe['usuario_nome'] ?? 'Usuário'); ?></small>
                        </div>
                    </div>
                    
                    <!-- Conversa -->
                    <div class="card">
                        <div class="card-header">
                            <strong><i class="fas fa-comments me-2"></i> Conversa</strong>
                            <small>(<?php echo count($respostas); ?> respostas)</small>
                        </div>
                        <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                            <?php if(empty($respostas)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-comment-slash fa-2x mb-2"></i>
                                    <p>Nenhuma resposta ainda.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach($respostas as $resp): ?>
                                <div class="resposta-item">
                                    <div class="resposta-header">
                                        <strong>
                                            <?php if($resp['usuario_id'] == $usuario_id): ?>
                                                <i class="fas fa-user-circle text-primary"></i> Você
                                            <?php else: ?>
                                                <i class="fas fa-headset text-success"></i> Equipe de Suporte
                                            <?php endif; ?>
                                        </strong>
                                        <small class="text-muted"><?php echo formatarDataHora($resp['data_resposta']); ?></small>
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars($resp['mensagem'])); ?></p>
                                    <?php if($resp['anexo']): ?>
                                    <div class="mt-2">
                                        <a href="../../uploads/chamados/<?php echo $resp['anexo']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-paperclip"></i> Ver Anexo
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Responder -->
                    <?php if($chamado_detalhe['status'] != 'fechado'): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <strong><i class="fas fa-reply me-2"></i> Responder</strong>
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="chamado_id" value="<?php echo $chamado_detalhe['id']; ?>">
                                <textarea name="mensagem" class="form-control" rows="4" placeholder="Digite sua resposta..." required></textarea>
                                <div class="mt-3">
                                    <label class="form-label">Anexar arquivo</label>
                                    <input type="file" name="anexo" class="form-control">
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="fechar_chamado" value="1" id="fechar">
                                    <label class="form-check-label" for="fechar">
                                        <i class="fas fa-check-circle"></i> Marcar como resolvido e fechar chamado
                                    </label>
                                </div>
                                <button type="submit" name="responder_chamado" class="btn btn-primary-custom mt-3">
                                    <i class="fas fa-paper-plane"></i> Enviar Resposta
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success mt-4">
                        <i class="fas fa-check-circle me-2"></i> Este chamado está fechado.
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="fecharModal()">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if($chamado_id_detalhe > 0): ?>
            var modal = new bootstrap.Modal(document.getElementById('modalVerChamado'));
            modal.show();
            <?php endif; ?>
        });
        
        function fecharModal() {
            var url = new URL(window.location.href);
            url.searchParams.delete('ver');
            window.history.pushState({}, '', url);
        }
    </script>
    <?php endif; ?>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda">
        <i class="fas fa-question"></i>
        <span class="tooltip-text">Precisa de ajuda?</span>
    </button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Ajuda - Chamados de Suporte</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="ajuda-section">
                        <h5><i class="fas fa-ticket-alt me-2"></i> Como abrir um chamado</h5>
                        <ol>
                            <li>Clique no botão "Novo Chamado"</li>
                            <li>Informe um título claro do problema</li>
                            <li>Selecione a categoria adequada</li>
                            <li>Defina a prioridade conforme a urgência</li>
                            <li>Descreva detalhadamente o problema</li>
                            <li>Clique em "Enviar Chamado"</li>
                        </ol>
                    </div>
                    
                    <div class="ajuda-section">
                        <h5><i class="fas fa-clock me-2"></i> Tempo de resposta</h5>
                        <ul>
                            <li><span class="badge bg-danger">Prioridade Alta</span> - até 4 horas úteis</li>
                            <li><span class="badge bg-warning text-dark">Prioridade Média</span> - até 24 horas úteis</li>
                            <li><span class="badge bg-success">Prioridade Baixa</span> - até 48 horas úteis</li>
                        </ul>
                    </div>
                    
                    <div class="ajuda-section">
                        <h5><i class="fas fa-comments me-2"></i> Acompanhamento</h5>
                        <ul>
                            <li>Você recebe notificações por email sobre atualizações</li>
                            <li>Pode anexar arquivos às respostas</li>
                            <li>Marque como resolvido quando o problema for solucionado</li>
                            <li>Chamados fechados ficam disponíveis para consulta</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i> 
                        <strong>Não encontrou solução?</strong> Ligue para nossa central: <strong>+244 923 456 789</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="faq.php" class="btn btn-primary-custom"><i class="fas fa-book"></i> Ver FAQ</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu toggle para mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
        });
        
        // Botão de ajuda
        document.getElementById('btnAjuda')?.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('modalAjuda')).show();
        });
        
        // Função para ver chamado
        function verChamado(id) {
            window.location.href = 'chamados.php?ver=' + id;
        }
        
        // Animações ao scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.chamado-card, .filter-card, .card').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'all 0.6s ease-out';
                observer.observe(el);
            });
        });
    </script>
</body>
</html>