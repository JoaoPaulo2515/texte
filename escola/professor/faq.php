<?php
// escola/professor/faq.php - FAQ (Perguntas Frequentes) para Professores

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
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'professor';
$papel = $_SESSION['papel'] ?? 'professor';

$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_suporte = ($usuario_tipo == 'suporte' || $papel == 'suporte');

// Para professores, apenas visualização (sem edição)
$can_edit = ($is_admin || $is_suporte);

// Filtros
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todas';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$success = '';
$error = '';

// Admin/Suporte: Adicionar FAQ
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_faq'])) {
    $pergunta = trim($_POST['pergunta'] ?? '');
    $resposta = trim($_POST['resposta'] ?? '');
    $categoria = $_POST['categoria'] ?? 'geral';
    $ordem = (int)($_POST['ordem'] ?? 0);
    
    if (empty($pergunta)) $error = "A pergunta é obrigatória.";
    elseif (empty($resposta)) $error = "A resposta é obrigatória.";
    else {
        try {
            $sql = "INSERT INTO faq (pergunta, resposta, categoria, ordem, escola_id, ativo, created_at) VALUES (:pergunta, :resposta, :categoria, :ordem, :escola_id, 1, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':pergunta' => $pergunta, ':resposta' => $resposta, ':categoria' => $categoria, ':ordem' => $ordem, ':escola_id' => $escola_id]);
            $success = "FAQ adicionado com sucesso!";
        } catch (Exception $e) { $error = "Erro: " . $e->getMessage(); }
    }
}

// Admin/Suporte: Editar FAQ
if ($can_edit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_faq'])) {
    $faq_id = (int)$_POST['faq_id'];
    $pergunta = trim($_POST['pergunta'] ?? '');
    $resposta = trim($_POST['resposta'] ?? '');
    $categoria = $_POST['categoria'] ?? 'geral';
    $ordem = (int)($_POST['ordem'] ?? 0);
    
    if (empty($pergunta)) $error = "A pergunta é obrigatória.";
    elseif (empty($resposta)) $error = "A resposta é obrigatória.";
    else {
        try {
            $sql = "UPDATE faq SET pergunta = :pergunta, resposta = :resposta, categoria = :categoria, ordem = :ordem WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':pergunta' => $pergunta, ':resposta' => $resposta, ':categoria' => $categoria, ':ordem' => $ordem, ':id' => $faq_id, ':escola_id' => $escola_id]);
            $success = "FAQ atualizado com sucesso!";
        } catch (Exception $e) { $error = "Erro: " . $e->getMessage(); }
    }
}

// Admin/Suporte: Excluir FAQ
if ($can_edit && isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    $faq_id = (int)$_GET['excluir'];
    try {
        $sql = "DELETE FROM faq WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $faq_id, ':escola_id' => $escola_id]);
        $success = "FAQ excluído com sucesso!";
    } catch (Exception $e) { $error = "Erro: " . $e->getMessage(); }
}

// Admin/Suporte: Alternar status
if ($can_edit && isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $faq_id = (int)$_GET['toggle'];
    try {
        $sql = "UPDATE faq SET ativo = NOT ativo WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $faq_id, ':escola_id' => $escola_id]);
        $success = "Status alterado!";
    } catch (Exception $e) { $error = "Erro: " . $e->getMessage(); }
}

// Buscar FAQs
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if (!$can_edit) $where_conditions[] = "ativo = 1";
if ($categoria_filtro != 'todas') { $where_conditions[] = "categoria = :categoria"; $params[':categoria'] = $categoria_filtro; }
if (!empty($busca)) { $where_conditions[] = "(pergunta LIKE :busca OR resposta LIKE :busca)"; $params[':busca'] = "%$busca%"; }
$where_conditions[] = "escola_id = :escola_id";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql_faq = "SELECT * FROM faq $where_sql ORDER BY ordem ASC, id ASC";
$stmt_faq = $conn->prepare($sql_faq);
$stmt_faq->execute($params);
$faqs = $stmt_faq->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por categoria
$faqs_por_categoria = [];
foreach ($faqs as $faq) { 
    $cat = $faq['categoria']; 
    if (!isset($faqs_por_categoria[$cat])) $faqs_por_categoria[$cat] = []; 
    $faqs_por_categoria[$cat][] = $faq; 
}

$categorias_disponiveis = [
    'geral' => ['nome' => 'Geral', 'icone' => 'fas fa-globe', 'cor' => '#006B3E'],
    'sistema' => ['nome' => 'Sistema', 'icone' => 'fas fa-desktop', 'cor' => '#1A2A6C'],
    'notas' => ['nome' => 'Notas e Avaliações', 'icone' => 'fas fa-edit', 'cor' => '#28a745'],
    'matricula' => ['nome' => 'Matrículas', 'icone' => 'fas fa-user-graduate', 'cor' => '#17a2b8'],
    'financeiro' => ['nome' => 'Financeiro', 'icone' => 'fas fa-money-bill', 'cor' => '#ffc107'],
    'tecnico' => ['nome' => 'Suporte Técnico', 'icone' => 'fas fa-laptop-code', 'cor' => '#fd7e14'],
    'academico' => ['nome' => 'Acadêmico', 'icone' => 'fas fa-book', 'cor' => '#6f42c1']
];

function getCategoriaInfo($categoria) { 
    global $categorias_disponiveis; 
    return $categorias_disponiveis[$categoria] ?? $categorias_disponiveis['geral']; 
}

function getStatusBadge($ativo) { 
    return $ativo == 1 ? '<span class="badge-status ativo"><i class="fas fa-check-circle"></i> Ativo</span>' : '<span class="badge-status inativo"><i class="fas fa-ban"></i> Inativo</span>'; 
}

function formatarData($data) { 
    return $data ? date('d/m/Y H:i', strtotime($data)) : '-'; 
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           VARIÁVEIS E RESET
        ============================================ */
        :root {
            --primary-color: #006B3E;
            --primary-dark: #004d2b;
            --primary-light: #e8f5e9;
            --secondary-color: #1A2A6C;
            --secondary-light: #e8ecf5;
            --accent-color: #FF6B35;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --dark: #2c3e50;
            --gray: #6c757d;
            --light-gray: #f8f9fa;
            --border-radius: 16px;
            --box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --box-shadow-hover: 0 20px 50px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
        }

        /* ============================================
           PAGE HEADER MODERN
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: 0.95rem;
        }

        /* Botões modernos */
        .btn-modern {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 107, 62, 0.3);
            color: white;
        }

        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            border-radius: 50px;
            padding: 8px 20px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-outline-custom:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        /* ============================================
           CARDS MODERNOS
        ============================================ */
        .card-modern {
            background: white;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            border: none;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            overflow: hidden;
        }

        .card-modern:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-hover);
        }

        .card-header-modern {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 18px 25px;
            border: none;
            font-weight: 600;
        }

        .card-header-modern h5 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ============================================
           CATEGORIAS CARDS
        ============================================ */
        .categoria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .categoria-card {
            background: white;
            border-radius: 12px;
            padding: 20px 15px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .categoria-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .categoria-card.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-color: var(--primary-color);
        }

        .categoria-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .categoria-nome {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .categoria-count {
            font-size: 0.75rem;
            opacity: 0.7;
        }

        /* ============================================
           FAQ ITEMS
        ============================================ */
        .faq-item {
            border-bottom: 1px solid #e9ecef;
            position: relative;
        }

        .faq-item:last-child {
            border-bottom: none;
        }

        .faq-question {
            padding: 20px 25px;
            cursor: pointer;
            transition: var(--transition);
            background: white;
        }

        .faq-question:hover {
            background: linear-gradient(90deg, var(--primary-light), white);
        }

        .faq-question h5 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0;
            color: var(--dark);
        }

        .faq-question .chevron-icon {
            transition: var(--transition);
            color: var(--primary-color);
        }

        .faq-answer {
            display: none;
            padding: 0 25px 25px 25px;
            background: var(--light-gray);
            border-top: 1px solid #e9ecef;
            animation: fadeInUp 0.3s ease-out;
        }

        .faq-answer.show {
            display: block;
        }

        .faq-answer-content {
            padding-top: 20px;
            color: var(--dark);
            line-height: 1.6;
        }

        /* ============================================
           BADGES
        ============================================ */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-status.ativo {
            background: linear-gradient(135deg, var(--success), #1e7e34);
            color: white;
        }

        .badge-status.inativo {
            background: linear-gradient(135deg, var(--gray), #5a6268);
            color: white;
        }

        /* ============================================
           SEARCH BOX
        ============================================ */
        .search-container {
            margin-bottom: 30px;
        }

        .search-box {
            max-width: 500px;
            margin: 0 auto;
        }

        .search-box .input-group {
            box-shadow: var(--box-shadow);
            border-radius: 50px;
            overflow: hidden;
        }

        .search-box .form-control {
            border: none;
            padding: 12px 20px;
            font-size: 0.95rem;
        }

        .search-box .form-control:focus {
            box-shadow: none;
        }

        .search-box .btn-search {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 0 25px;
        }

        /* ============================================
           ADMIN ACTIONS
        ============================================ */
        .admin-actions {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            gap: 5px;
        }

        .admin-actions .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            background: white;
            border: 1px solid #e9ecef;
            color: var(--gray);
        }

        .admin-actions .btn-icon:hover {
            transform: scale(1.1);
        }

        .admin-actions .btn-edit:hover {
            background: var(--info);
            color: white;
            border-color: var(--info);
        }

        .admin-actions .btn-delete:hover {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        .admin-actions .btn-toggle:hover {
            background: var(--warning);
            color: white;
            border-color: var(--warning);
        }

        /* ============================================
           CHAMADO CARD
        ============================================ */
        .chamado-card {
            background: linear-gradient(135deg, var(--primary-light), white);
            border-radius: var(--border-radius);
            padding: 30px;
            text-align: center;
            margin-top: 30px;
        }

        .chamado-card i {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .animated {
            animation: fadeInUp 0.6s ease-out;
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        /* ============================================
           MODAL MODERNO
        ============================================ */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            overflow: hidden;
        }

        .modal-header-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 20px 25px;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 20px 25px;
        }

        /* ============================================
           BOTÃO AJUDA FLUTUANTE
        ============================================ */
        .btn-floating-help {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            z-index: 1000;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-floating-help:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.3);
        }

        .btn-floating-help i {
            font-size: 28px;
        }

        .btn-floating-help .tooltip-text {
            position: absolute;
            right: 70px;
            background: var(--dark);
            color: white;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .btn-floating-help:hover .tooltip-text {
            opacity: 1;
        }

        @media (max-width: 768px) {
            .btn-floating-help {
                bottom: 20px;
                right: 20px;
                width: 50px;
                height: 50px;
            }
            .btn-floating-help i {
                font-size: 24px;
            }
        }

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
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* ============================================
           MENU TOGGLE
        ============================================ */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: var(--box-shadow);
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .page-header {
                padding: 20px;
            }
            
            .page-header h2 {
                font-size: 1.3rem;
            }
            
            .categoria-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 10px;
            }
            
            .categoria-card {
                padding: 12px;
            }
            
            .categoria-icon {
                font-size: 1.5rem;
            }
            
            .categoria-nome {
                font-size: 0.75rem;
            }
            
            .faq-question {
                padding: 15px;
            }
            
            .admin-actions {
                position: static;
                transform: none;
                margin-top: 10px;
                justify-content: flex-end;
            }
        }

        /* ============================================
           PRINT STYLES
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-primary-custom, .btn-floating-help, .admin-actions, .search-container, .categoria-grid {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .page-header {
                background: var(--primary-color);
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .faq-answer {
                display: block !important;
            }
        }

        /* ============================================
           UTILITÁRIOS
        ============================================ */
        .text-gradient {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .link-copy {
            color: var(--info);
            text-decoration: none;
            font-size: 0.8rem;
        }

        .link-copy:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
   
    
    <?php include 'includes/menu_professor.php'; ?>
    
</br></br>
</br>
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-question-circle me-2"></i>Perguntas Frequentes</h2>
                    <p class="mb-0">Encontre respostas para as dúvidas mais comuns sobre o sistema</p>
                </div>
                <div class="no-print d-flex gap-2 flex-wrap">
                    <a href="dashboard.php" class="btn-voltar btn-modern">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <?php if($can_edit): ?>
                        <button class="btn-primary-custom btn-modern" data-bs-toggle="modal" data-bs-target="#modalAdicionarFAQ">
                            <i class="fas fa-plus"></i> Adicionar FAQ
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show animated" role="alert" style="border-radius: 12px;">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show animated" role="alert" style="border-radius: 12px;">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Busca -->
        <div class="search-container animated" style="animation-delay: 0.1s;">
            <form method="GET" class="search-box">
                <div class="input-group">
                    <input type="text" name="busca" class="form-control" placeholder="🔍 Buscar perguntas..." value="<?php echo htmlspecialchars($busca); ?>">
                    <input type="hidden" name="categoria" value="<?php echo $categoria_filtro; ?>">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                    <?php if(!empty($busca) || $categoria_filtro != 'todas'): ?>
                        <a href="faq.php" class="btn btn-outline-secondary" style="border-radius: 0 50px 50px 0;">
                            <i class="fas fa-times"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <!-- Categorias -->
        <div class="categoria-grid animated" style="animation-delay: 0.2s;">
            <div class="categoria-card <?php echo $categoria_filtro=='todas'?'active':''; ?>" onclick="filtrarCategoria('todas')">
                <div class="categoria-icon"><i class="fas fa-list-ul"></i></div>
                <div class="categoria-nome">Todas</div>
                <div class="categoria-count"><?php echo count($faqs); ?> perguntas</div>
            </div>
            <?php foreach($categorias_disponiveis as $key => $cat): 
                $count = isset($faqs_por_categoria[$key]) ? count($faqs_por_categoria[$key]) : 0; 
                if($count > 0 || $can_edit): ?>
                <div class="categoria-card <?php echo $categoria_filtro==$key?'active':''; ?>" onclick="filtrarCategoria('<?php echo $key; ?>')">
                    <div class="categoria-icon"><i class="<?php echo $cat['icone']; ?>"></i></div>
                    <div class="categoria-nome"><?php echo $cat['nome']; ?></div>
                    <div class="categoria-count"><?php echo $count; ?> perguntas</div>
                </div>
            <?php endif; endforeach; ?>
        </div>
        
        <!-- FAQs -->
        <?php if(empty($faqs)): ?>
            <div class="card-modern animated" style="animation-delay: 0.3s;">
                <div class="text-center py-5">
                    <i class="fas fa-question-circle fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">Nenhuma pergunta encontrada</h4>
                    <p class="text-muted">Tente buscar por outro termo ou categoria</p>
                    <?php if($can_edit): ?>
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalAdicionarFAQ">
                            <i class="fas fa-plus"></i> Adicionar primeira FAQ
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php $display_faqs = ($categoria_filtro!='todas' && isset($faqs_por_categoria[$categoria_filtro])) ? [$categoria_filtro=>$faqs_por_categoria[$categoria_filtro]] : $faqs_por_categoria; ?>
            <?php foreach($display_faqs as $cat_key => $faqs_lista): 
                $cat_info = getCategoriaInfo($cat_key); ?>
                <div class="card-modern animated" style="animation-delay: 0.3s;">
                    <div class="card-header-modern">
                        <h5>
                            <i class="<?php echo $cat_info['icone']; ?>"></i>
                            <?php echo $cat_info['nome']; ?>
                            <span class="badge bg-light text-dark ms-2 rounded-pill"><?php echo count($faqs_lista); ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach($faqs_lista as $faq): ?>
                            <div class="faq-item" id="faq-<?php echo $faq['id']; ?>">
                                <div class="faq-question d-flex justify-content-between align-items-center flex-wrap" onclick="toggleResposta(this)">
                                    <div class="d-flex align-items-center gap-3">
                                        <i class="fas fa-chevron-right chevron-icon text-muted"></i>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($faq['pergunta']); ?></h5>
                                        <?php if($can_edit && $faq['ativo'] == 0): ?>
                                            <?php echo getStatusBadge($faq['ativo']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if($can_edit): ?>
                                        <div class="admin-actions" onclick="event.stopPropagation()">
                                            <button class="btn-icon btn-edit" onclick="editarFAQ(<?php echo $faq['id']; ?>, '<?php echo addslashes($faq['pergunta']); ?>', '<?php echo addslashes($faq['resposta']); ?>', '<?php echo $faq['categoria']; ?>', <?php echo $faq['ordem']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?excluir=<?php echo $faq['id']; ?>" class="btn-icon btn-delete" onclick="return confirm('Tem certeza que deseja excluir esta pergunta?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <a href="?toggle=<?php echo $faq['id']; ?>" class="btn-icon btn-toggle">
                                                <i class="fas fa-<?php echo $faq['ativo']==1?'eye-slash':'eye'; ?>"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="faq-answer">
                                    <div class="faq-answer-content">
                                        <?php echo nl2br(htmlspecialchars($faq['resposta'])); ?>
                                        <div class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i> 
                                                Atualizado: <?php echo formatarData($faq['updated_at'] ?? $faq['created_at']); ?>
                                            </small>
                                            <button class="link-copy" onclick="copiarLink(<?php echo $faq['id']; ?>)">
                                                <i class="fas fa-link"></i> Copiar link desta resposta
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Chamado de Suporte -->
        <div class="chamado-card animated" style="animation-delay: 0.4s;">
            <i class="fas fa-headset"></i>
            <h5 class="mb-2">Não encontrou o que procurava?</h5>
            <p class="text-muted mb-3">Nossa equipe de suporte está pronta para ajudar você</p>
            <a href="chamados.php" class="btn-primary-custom btn-modern">
                <i class="fas fa-ticket-alt"></i> Abrir Chamado de Suporte
            </a>
        </div>
    </div>
    
    <!-- Modais Admin -->
    <?php if($can_edit): ?>
    <!-- Modal Adicionar FAQ -->
    <div class="modal fade" id="modalAdicionarFAQ" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Adicionar Nova Pergunta</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Pergunta *</label>
                            <input type="text" name="pergunta" class="form-control" placeholder="Ex: Como faço para lançar notas?" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Resposta *</label>
                            <textarea name="resposta" class="form-control" rows="5" placeholder="Digite a resposta detalhada..." required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Categoria</label>
                                <select name="categoria" class="form-select">
                                    <option value="geral">📌 Geral</option>
                                    <option value="sistema">💻 Sistema</option>
                                    <option value="notas">📝 Notas e Avaliações</option>
                                    <option value="matricula">🎓 Matrículas</option>
                                    <option value="financeiro">💰 Financeiro</option>
                                    <option value="tecnico">🔧 Suporte Técnico</option>
                                    <option value="academico">📚 Acadêmico</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Ordem de Exibição</label>
                                <input type="number" name="ordem" class="form-control" value="0" placeholder="0 = primeiro">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="adicionar_faq" class="btn-primary-custom">Salvar FAQ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar FAQ -->
    <div class="modal fade" id="modalEditarFAQ" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Editar FAQ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="faq_id" id="edit_faq_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Pergunta *</label>
                            <input type="text" name="pergunta" id="edit_pergunta" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Resposta *</label>
                            <textarea name="resposta" id="edit_resposta" class="form-control" rows="5" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Categoria</label>
                                <select name="categoria" id="edit_categoria" class="form-select">
                                    <option value="geral">📌 Geral</option>
                                    <option value="sistema">💻 Sistema</option>
                                    <option value="notas">📝 Notas e Avaliações</option>
                                    <option value="matricula">🎓 Matrículas</option>
                                    <option value="financeiro">💰 Financeiro</option>
                                    <option value="tecnico">🔧 Suporte Técnico</option>
                                    <option value="academico">📚 Acadêmico</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Ordem de Exibição</label>
                                <input type="number" name="ordem" id="edit_ordem" class="form-control" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="editar_faq" class="btn-primary-custom">Atualizar FAQ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Botão de Ajuda Flutuante -->
    <button class="btn-floating-help" id="btnAjuda">
        <i class="fas fa-question"></i>
        <span class="tooltip-text">Precisa de ajuda?</span>
    </button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Ajuda - FAQ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="p-3" style="background: var(--primary-light); border-radius: 12px;">
                                <h6 class="text-primary mb-3"><i class="fas fa-search me-2"></i> Como encontrar respostas</h6>
                                <ul class="mb-0">
                                    <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> Use a busca por palavras-chave</li>
                                    <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> Filtre por categorias específicas</li>
                                    <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> Clique na pergunta para ver a resposta</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3" style="background: var(--secondary-light); border-radius: 12px;">
                                <h6 class="text-secondary mb-3"><i class="fas fa-link me-2"></i> Compartilhar respostas</h6>
                                <ul class="mb-0">
                                    <li class="mb-2"><i class="fas fa-check-circle text-info me-2"></i> Clique em "Copiar link desta resposta"</li>
                                    <li class="mb-2"><i class="fas fa-check-circle text-info me-2"></i> Compartilhe o link com colegas</li>
                                    <li class="mb-2"><i class="fas fa-check-circle text-info me-2"></i> A resposta será destacada ao abrir</li>
                                </ul>
                            </div>
                        </div>
                        <?php if($can_edit): ?>
                        <div class="col-12">
                            <div class="p-3" style="background: #fff3e0; border-radius: 12px;">
                                <h6 class="text-warning mb-3"><i class="fas fa-shield-alt me-2"></i> Para Administradores</h6>
                                <ul class="mb-0">
                                    <li class="mb-2"><i class="fas fa-edit me-2"></i> Adicionar, editar e excluir FAQs</li>
                                    <li class="mb-2"><i class="fas fa-eye-slash me-2"></i> Ativar/desativar perguntas</li>
                                    <li class="mb-2"><i class="fas fa-sort-numeric-down me-2"></i> Controlar ordem de exibição</li>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="alert alert-info mt-4 mb-0" style="border-radius: 12px;">
                        <i class="fas fa-lightbulb me-2"></i> 
                        <strong>Não encontrou sua resposta?</strong> 
                        <a href="chamados.php" class="alert-link">Abra um chamado de suporte</a> e nossa equipe irá te ajudar.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="chamados.php" class="btn-primary-custom">
                        <i class="fas fa-headset"></i> Abrir Chamado
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu Toggle
        document.getElementById('menuToggle')?.addEventListener('click', function() { 
            document.querySelector('.sidebar')?.classList.toggle('active'); 
            document.querySelector('.main-content')?.classList.toggle('active'); 
        });
        
        // Botão de ajuda
        document.getElementById('btnAjuda')?.addEventListener('click', function() { 
            new bootstrap.Modal(document.getElementById('modalAjuda')).show(); 
        });
        
        // Toggle resposta
        function toggleResposta(element) { 
            const answer = element.nextElementSibling; 
            const icon = element.querySelector('.chevron-icon');
            const parent = element.closest('.faq-item');
            
            // Fechar outras respostas
            document.querySelectorAll('.faq-answer.show').forEach(a => {
                if(a !== answer) {
                    a.classList.remove('show');
                    const prevIcon = a.previousElementSibling?.querySelector('.chevron-icon');
                    if(prevIcon) prevIcon.style.transform = 'rotate(0deg)';
                }
            });
            
            if(answer.classList.contains('show')) {
                answer.classList.remove('show'); 
                if(icon) icon.style.transform = 'rotate(0deg)';
            } else { 
                answer.classList.add('show'); 
                if(icon) icon.style.transform = 'rotate(90deg)';
            } 
        }
        
        // Filtrar por categoria
        function filtrarCategoria(categoria) { 
            const url = new URL(window.location.href); 
            if(categoria === 'todas') {
                url.searchParams.delete('categoria');
            } else { 
                url.searchParams.set('categoria', categoria);
            }
            window.location.href = url.toString(); 
        }
        
        // Copiar link
        function copiarLink(faqId) { 
            const url = window.location.href.split('#')[0] + '#faq-' + faqId; 
            navigator.clipboard.writeText(url).then(() => {
                // Feedback visual
                const btn = event.target.closest('.link-copy');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Link copiado!';
                setTimeout(() => {
                    btn.innerHTML = originalText;
                }, 2000);
            }).catch(() => alert('Erro ao copiar link')); 
        }
        
        <?php if($can_edit): ?>
        // Editar FAQ
        function editarFAQ(id, pergunta, resposta, categoria, ordem) { 
            document.getElementById('edit_faq_id').value = id; 
            document.getElementById('edit_pergunta').value = pergunta; 
            document.getElementById('edit_resposta').value = resposta; 
            document.getElementById('edit_categoria').value = categoria; 
            document.getElementById('edit_ordem').value = ordem; 
            new bootstrap.Modal(document.getElementById('modalEditarFAQ')).show(); 
        }
        <?php endif; ?>
        
        // Auto-fechar alertas
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        }, 100);
        
        // Expandir FAQ a partir de hash na URL
        if(window.location.hash) { 
            const faqId = window.location.hash.replace('#faq-', ''); 
            const faqElement = document.getElementById(`faq-${faqId}`); 
            if(faqElement) { 
                const questionDiv = faqElement.querySelector('.faq-question'); 
                if(questionDiv) toggleResposta(questionDiv); 
                faqElement.scrollIntoView({ behavior: 'smooth', block: 'center' }); 
            } 
        }
        
        // Animações de entrada
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.animated');
            elements.forEach((el, index) => {
                el.style.animationDelay = (index * 0.1) + 's';
            });
        });
    </script>
</body>
</html>