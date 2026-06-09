<?php
// escola/suporte/tutoriais.php - Central de Vídeos Tutoriais

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
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_suporte = ($usuario_tipo == 'suporte' || $papel == 'suporte');

// DESATIVADO: Variável para controlar se o admin pode adicionar tutoriais
$can_add_tutorial = false; // ALTERADO: Desativado permanentemente

// Filtros
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todos';
$nivel_filtro = isset($_GET['nivel']) ? $_GET['nivel'] : 'todos';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$success = '';
$error = '';

// DESATIVADO: Bloco de adição de tutorial (agora só permite se $can_add_tutorial for true)
if ($can_add_tutorial && ($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_tutorial'])) {
    // Código de adição removido/desativado
}

// DESATIVADO: Bloco de edição de tutorial
if ($can_add_tutorial && ($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_tutorial'])) {
    // Código de edição removido/desativado
}

// DESATIVADO: Bloco de exclusão de tutorial
if ($can_add_tutorial && ($is_admin || $is_suporte) && isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    // Código de exclusão removido/desativado
}

// DESATIVADO: Bloco de alternar status
if ($can_add_tutorial && ($is_admin || $is_suporte) && isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    // Código de toggle removido/desativado
}

// Registrar visualização (mantido ativo)
if (isset($_GET['visualizar']) && is_numeric($_GET['visualizar'])) {
    $tutorial_id = (int)$_GET['visualizar'];
    $sql = "UPDATE tutoriais SET visualizacoes = visualizacoes + 1 WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $tutorial_id]);
}

// Buscar tutoriais
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if (!$is_admin && !$is_suporte) {
    $where_conditions[] = "ativo = 1";
}

if ($categoria_filtro != 'todos') {
    $where_conditions[] = "categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}

if ($nivel_filtro != 'todos') {
    $where_conditions[] = "nivel = :nivel";
    $params[':nivel'] = $nivel_filtro;
}

if (!empty($busca)) {
    $where_conditions[] = "(titulo LIKE :busca OR descricao LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_conditions[] = "escola_id = :escola_id";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql_tutoriais = "SELECT * FROM tutoriais $where_sql ORDER BY destaque DESC, ordem ASC, visualizacoes DESC, id ASC";
$stmt_tutoriais = $conn->prepare($sql_tutoriais);
$stmt_tutoriais->execute($params);
$tutoriais = $stmt_tutoriais->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por categoria
$tutoriais_por_categoria = [];
$categorias = [
    'sistema' => ['nome' => 'Sistema', 'icone' => 'fas fa-desktop', 'cor' => '#006B3E', 'descricao' => 'Tutoriais sobre o funcionamento geral do sistema'],
    'notas' => ['nome' => 'Lançamento de Notas', 'icone' => 'fas fa-edit', 'cor' => '#28a745', 'descricao' => 'Como lançar e gerenciar notas'],
    'matricula' => ['nome' => 'Matrículas', 'icone' => 'fas fa-user-graduate', 'cor' => '#17a2b8', 'descricao' => 'Processos de matrícula e rematrícula'],
    'financeiro' => ['nome' => 'Financeiro', 'icone' => 'fas fa-money-bill', 'cor' => '#dc3545', 'descricao' => 'Gestão financeira e pagamentos'],
    'relatorios' => ['nome' => 'Relatórios', 'icone' => 'fas fa-chart-bar', 'cor' => '#fd7e14', 'descricao' => 'Emissão de relatórios e boletins'],
    'perfil' => ['nome' => 'Perfil', 'icone' => 'fas fa-user', 'cor' => '#6c757d', 'descricao' => 'Configurações do perfil do usuário']
];

$niveis = [
    'iniciante' => ['nome' => 'Iniciante', 'icone' => 'fas fa-star', 'cor' => '#28a745'],
    'intermediario' => ['nome' => 'Intermediário', 'icone' => 'fas fa-star-half-alt', 'cor' => '#fd7e14'],
    'avancado' => ['nome' => 'Avançado', 'icone' => 'fas fa-star-of-life', 'cor' => '#dc3545']
];

foreach ($tutoriais as $tutorial) {
    $cat = $tutorial['categoria'];
    if (!isset($tutoriais_por_categoria[$cat])) {
        $tutoriais_por_categoria[$cat] = [];
    }
    $tutoriais_por_categoria[$cat][] = $tutorial;
}

// Estatísticas
$total_tutoriais = count($tutoriais);
$total_visualizacoes = array_sum(array_column($tutoriais, 'visualizacoes'));

// Funções auxiliares
function getCategoriaInfo($categoria) {
    global $categorias;
    return $categorias[$categoria] ?? ['nome' => ucfirst($categoria), 'icone' => 'fas fa-folder', 'cor' => '#6c757d', 'descricao' => ''];
}

function getNivelInfo($nivel) {
    global $niveis;
    return $niveis[$nivel] ?? ['nome' => ucfirst($nivel), 'icone' => 'fas fa-circle', 'cor' => '#6c757d'];
}

function formatarData($data) {
    return $data ? date('d/m/Y', strtotime($data)) : '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vídeos Tutoriais | SIGE Angola</title>
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

        /* Botão desativado */
        .btn-disabled {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .btn-disabled:hover {
            transform: none;
            box-shadow: none;
        }

        /* ============================================
           STATS CARDS
        ============================================ */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            text-align: center;
            transition: var(--transition);
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-hover);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        /* ============================================
           SEARCH BOX
        ============================================ */
        .search-container {
            margin-bottom: 30px;
        }

        .search-box {
            max-width: 600px;
            margin: 0 auto;
        }

        .search-box .input-group {
            box-shadow: var(--box-shadow);
            border-radius: 50px;
            overflow: hidden;
            background: white;
        }

        .search-box .form-control {
            border: none;
            padding: 14px 24px;
            font-size: 0.95rem;
        }

        .search-box .form-control:focus {
            box-shadow: none;
        }

        .search-box .btn-search {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 0 30px;
            font-weight: 600;
        }

        /* ============================================
           FILTERS
        ============================================ */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .filter-title {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-title i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 12px;
        }

        .filter-card {
            background: var(--light-gray);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .filter-card:hover {
            transform: translateY(-3px);
            background: var(--primary-light);
        }

        .filter-card.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-color: var(--primary-color);
        }

        .filter-icon {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .filter-name {
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* ============================================
           TUTORIAL CARDS
        ============================================ */
        .tutorial-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
        }

        .tutorial-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
            box-shadow: var(--box-shadow);
        }

        .tutorial-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--box-shadow-hover);
        }

        .video-thumb {
            position: relative;
            background: linear-gradient(135deg, #000, #1a1a2e);
            overflow: hidden;
            height: 200px;
        }

        .video-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .tutorial-card:hover .video-thumb img {
            transform: scale(1.05);
            opacity: 0.75;
        }

        .play-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            opacity: 0;
        }

        .tutorial-card:hover .play-overlay {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1.1);
        }

        .play-overlay i {
            font-size: 28px;
            color: var(--primary-color);
            margin-left: 3px;
        }

        .video-duration {
            position: absolute;
            bottom: 12px;
            right: 12px;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(5px);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .destaque-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            background: linear-gradient(135deg, var(--warning), #ff9800);
            color: #333;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            z-index: 5;
        }

        /* Botões Admin - Ocultos completamente */
        .admin-actions {
            display: none !important;
        }

        .card-body-content {
            padding: 20px;
        }

        .badge-categoria {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-nivel {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .tutorial-title {
            font-size: 1rem;
            font-weight: 700;
            margin: 12px 0 8px;
            color: var(--dark);
            line-height: 1.4;
        }

        .tutorial-desc {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .tutorial-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid #e9ecef;
            font-size: 0.75rem;
            color: var(--gray);
        }

        /* ============================================
           EMPTY STATE
        ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gray);
            margin-bottom: 20px;
        }

        .empty-state h4 {
            color: var(--dark);
            margin-bottom: 10px;
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

        .embed-responsive {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            border-radius: 12px;
        }

        .embed-responsive iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border-radius: 12px;
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

        .animated {
            animation: fadeInUp 0.6s ease-out;
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
            
            .tutorial-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .filter-grid {
                grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            }
            
            .stats-container {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }

        /* ============================================
           PRINT STYLES
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-primary-custom, .btn-floating-help, 
            .search-container, .filter-section, .stats-container {
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
            
            .tutorial-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
            animation: fadeInUp 0.5s ease-out;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c8e6d9);
            color: #155724;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left: 4px solid var(--danger);
        }
        
        /* Tooltip informativo */
        .info-tooltip {
            background: var(--info);
            color: white;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 8px;
            cursor: help;
        }
    </style>
</head>
<body>
      <?php include 'includes/menu_professor.php'; ?>
</br></br></br>
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-video me-2"></i>Vídeos Tutoriais</h2>
                    <p class="mb-0">Aprenda a usar o sistema com nossos vídeos tutoriais passo a passo</p>
                </div>
                <div class="no-print d-flex gap-2 flex-wrap">
                    <a href="../dashboard.php" class="btn-voltar btn-modern"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <!-- Botão Adicionar Tutorial - DESATIVADO e OCULTO -->
                    <?php if (false): // Botão permanentemente desativado ?>
                        <button class="btn-primary-custom btn-modern" data-bs-toggle="modal" data-bs-target="#modalAdicionarTutorial">
                            <i class="fas fa-plus"></i> Adicionar Tutorial
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert-custom alert-success animated"><i class="fas fa-check-circle me-2"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-custom alert-danger animated"><i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-container animated" style="animation-delay: 0.1s;">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-video"></i></div>
                <div class="stat-number"><?php echo $total_tutoriais; ?></div>
                <div class="stat-label">Vídeos Tutoriais</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-eye"></i></div>
                <div class="stat-number"><?php echo number_format($total_visualizacoes); ?></div>
                <div class="stat-label">Visualizações Totais</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-folder"></i></div>
                <div class="stat-number"><?php echo count(array_filter($tutoriais_por_categoria)); ?></div>
                <div class="stat-label">Categorias Ativas</div>
            </div>
        </div>
        
        <!-- Busca -->
        <div class="search-container animated" style="animation-delay: 0.15s;">
            <form method="GET" class="search-box">
                <div class="input-group">
                    <input type="text" name="busca" class="form-control" placeholder="🔍 Buscar tutoriais por título ou descrição..." value="<?php echo htmlspecialchars($busca); ?>">
                    <input type="hidden" name="categoria" value="<?php echo $categoria_filtro; ?>">
                    <input type="hidden" name="nivel" value="<?php echo $nivel_filtro; ?>">
                    <button type="submit" class="btn-search"><i class="fas fa-search"></i> Buscar</button>
                    <?php if (!empty($busca) || $categoria_filtro != 'todos' || $nivel_filtro != 'todos'): ?>
                        <a href="tutoriais.php" class="btn btn-outline-secondary" style="border-radius: 0 50px 50px 0;"><i class="fas fa-times"></i> Limpar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <!-- Filtros -->
        <div class="filter-section animated" style="animation-delay: 0.2s;">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="filter-title"><i class="fas fa-tag"></i> Categorias</div>
                    <div class="filter-grid">
                        <div class="filter-card <?php echo $categoria_filtro == 'todos' ? 'active' : ''; ?>" onclick="filtrarCategoria('todos')">
                            <div class="filter-icon"><i class="fas fa-list-ul"></i></div>
                            <div class="filter-name">Todos</div>
                        </div>
                        <?php foreach ($categorias as $key => $cat): ?>
                            <div class="filter-card <?php echo $categoria_filtro == $key ? 'active' : ''; ?>" onclick="filtrarCategoria('<?php echo $key; ?>')">
                                <div class="filter-icon"><i class="<?php echo $cat['icone']; ?>"></i></div>
                                <div class="filter-name"><?php echo $cat['nome']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="filter-title"><i class="fas fa-chart-line"></i> Nível de Dificuldade</div>
                    <div class="filter-grid">
                        <div class="filter-card <?php echo $nivel_filtro == 'todos' ? 'active' : ''; ?>" onclick="filtrarNivel('todos')">
                            <div class="filter-icon"><i class="fas fa-list-ul"></i></div>
                            <div class="filter-name">Todos</div>
                        </div>
                        <?php foreach ($niveis as $key => $nivel): ?>
                            <div class="filter-card <?php echo $nivel_filtro == $key ? 'active' : ''; ?>" onclick="filtrarNivel('<?php echo $key; ?>')">
                                <div class="filter-icon"><i class="<?php echo $nivel['icone']; ?>"></i></div>
                                <div class="filter-name"><?php echo $nivel['nome']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Lista de Tutoriais -->
        <?php if (empty($tutoriais)): ?>
            <div class="empty-state animated" style="animation-delay: 0.25s;">
                <i class="fas fa-video-slash"></i>
                <h4>Nenhum tutorial encontrado</h4>
                <p class="text-muted">Não há vídeos tutoriais disponíveis nesta categoria ou com este filtro.</p>
            </div>
        <?php else: ?>
            <div class="tutorial-grid animated" style="animation-delay: 0.25s;">
                <?php foreach ($tutoriais as $tutorial): 
                    $cat_info = getCategoriaInfo($tutorial['categoria']);
                    $nivel_info = getNivelInfo($tutorial['nivel']);
                    $thumb_url = ($tutorial['plataforma'] == 'youtube' && !empty($tutorial['video_id'])) 
                        ? "https://img.youtube.com/vi/{$tutorial['video_id']}/mqdefault.jpg" 
                        : "https://placehold.co/640x360/1A2A6C/FFFFFF?text=SIGE+Tutorial";
                ?>
                    <div class="tutorial-card" onclick="abrirTutorial(<?php echo $tutorial['id']; ?>, '<?php echo addslashes($tutorial['titulo']); ?>', '<?php echo addslashes($tutorial['descricao']); ?>', '<?php echo $tutorial['embed_url']; ?>', '<?php echo $tutorial['plataforma']; ?>', '<?php echo addslashes($tutorial['url_video']); ?>')">
                        <!-- Admin Actions - Completamente removidas -->
                        <?php if ($tutorial['destaque'] == 1): ?>
                            <div class="destaque-badge"><i class="fas fa-star"></i> Destaque</div>
                        <?php endif; ?>
                        <div class="video-thumb">
                            <img src="<?php echo $thumb_url; ?>" alt="<?php echo htmlspecialchars($tutorial['titulo']); ?>">
                            <div class="play-overlay">
                                <i class="fas fa-play"></i>
                            </div>
                            <?php if ($tutorial['duracao']): ?>
                                <div class="video-duration"><i class="fas fa-clock"></i> <?php echo $tutorial['duracao']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body-content">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="badge-categoria" style="background: <?php echo $cat_info['cor']; ?>20; color: <?php echo $cat_info['cor']; ?>">
                                    <i class="<?php echo $cat_info['icone']; ?>"></i> <?php echo $cat_info['nome']; ?>
                                </span>
                                <span class="badge-nivel" style="background: <?php echo $nivel_info['cor']; ?>20; color: <?php echo $nivel_info['cor']; ?>">
                                    <i class="<?php echo $nivel_info['icone']; ?>"></i> <?php echo $nivel_info['nome']; ?>
                                </span>
                            </div>
                            <h5 class="tutorial-title"><?php echo htmlspecialchars($tutorial['titulo']); ?></h5>
                            <p class="tutorial-desc"><?php echo htmlspecialchars(substr($tutorial['descricao'], 0, 100)) . (strlen($tutorial['descricao']) > 100 ? '...' : ''); ?></p>
                            <div class="tutorial-footer">
                                <span><i class="fas fa-eye"></i> <?php echo number_format($tutorial['visualizacoes']); ?></span>
                                <span><i class="fas fa-calendar-alt"></i> <?php echo formatarData($tutorial['created_at']); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modais - REMOVIDOS/COMENTADOS (mantidos apenas o de visualização) -->
    
    <!-- Modal Visualizar Tutorial -->
    <div class="modal fade" id="modalVerTutorial" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalTutorialTitulo"><i class="fas fa-video me-2"></i> Tutorial</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="embed-responsive" id="videoContainer"></div>
                    <div class="mt-4">
                        <p id="modalTutorialDescricao" class="text-muted"></p>
                        <hr>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <a href="#" id="linkVideo" class="btn btn-outline-primary" target="_blank">
                                <i class="fas fa-external-link-alt"></i> Abrir no YouTube
                            </a>
                            <small class="text-muted" id="modalInfo"></small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="tutoriais.php" class="btn-primary-custom">Ver mais tutoriais</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botão de Ajuda -->
    <button class="btn-floating-help" id="btnAjuda">
        <i class="fas fa-question"></i>
        <span class="tooltip-text">Precisa de ajuda?</span>
    </button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Ajuda - Vídeos Tutoriais</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="p-3" style="background: var(--primary-light); border-radius: 12px;">
                                <h6 class="text-primary mb-3"><i class="fas fa-video me-2"></i> Sobre os Tutoriais</h6>
                                <p class="small">Aprenda a usar todas as funcionalidades do sistema através de nossos vídeos tutoriais, organizados por categoria e nível de dificuldade.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3" style="background: var(--secondary-light); border-radius: 12px;">
                                <h6 class="text-secondary mb-3"><i class="fas fa-search me-2"></i> Como encontrar</h6>
                                <ul class="small mb-0">
                                    <li>Use a busca por palavras-chave</li>
                                    <li>Filtre por categoria (Sistema, Notas, etc.)</li>
                                    <li>Filtre por nível de dificuldade</li>
                                    <li>Clique no card para assistir</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info mt-4 mb-0" style="border-radius: 12px;">
                        <i class="fas fa-lightbulb me-2"></i> 
                        <strong>Dica:</strong> Assista todos os tutoriais para aproveitar ao máximo o sistema! 
                        <a href="faq.php" class="alert-link">Ver FAQ</a> ou 
                        <a href="chamados.php" class="alert-link">Abrir Chamado</a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="faq.php" class="btn-primary-custom"><i class="fas fa-book"></i> FAQ</a>
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
        
        // Filtros
        function filtrarCategoria(categoria) {
            const url = new URL(window.location.href);
            if (categoria === 'todos') {
                url.searchParams.delete('categoria');
            } else {
                url.searchParams.set('categoria', categoria);
            }
            window.location.href = url.toString();
        }
        
        function filtrarNivel(nivel) {
            const url = new URL(window.location.href);
            if (nivel === 'todos') {
                url.searchParams.delete('nivel');
            } else {
                url.searchParams.set('nivel', nivel);
            }
            window.location.href = url.toString();
        }
        
        // Registrar visualização
        function registrarVisualizacao(tutorialId) {
            fetch('tutoriais.php?visualizar=' + tutorialId, { method: 'GET' });
        }
        
        // Abrir tutorial
        function abrirTutorial(id, titulo, descricao, embedUrl, plataforma, urlVideo) {
            document.getElementById('modalTutorialTitulo').innerHTML = '<i class="fas fa-video me-2"></i> ' + titulo;
            document.getElementById('modalTutorialDescricao').innerHTML = descricao;
            document.getElementById('linkVideo').href = urlVideo;
            
            var videoHtml = '';
            if (plataforma === 'youtube') {
                videoHtml = '<iframe src="' + embedUrl + '?autoplay=1&rel=0" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
            } else if (plataforma === 'vimeo') {
                videoHtml = '<iframe src="' + embedUrl + '?autoplay=1" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
            } else {
                videoHtml = '<div class="alert alert-info text-center"><i class="fas fa-info-circle fa-2x mb-2 d-block"></i>Vídeo disponível no link abaixo.</div>';
            }
            
            document.getElementById('videoContainer').innerHTML = videoHtml;
            document.getElementById('modalInfo').innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Registrando visualização...';
            
            new bootstrap.Modal(document.getElementById('modalVerTutorial')).show();
            registrarVisualizacao(id);
            
            setTimeout(function() {
                document.getElementById('modalInfo').innerHTML = '<i class="fas fa-check-circle"></i> Visualização registrada';
            }, 1000);
        }
        
        // Auto-fechar alertas
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        }, 100);
        
        // Animações
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.animated');
            elements.forEach((el, index) => {
                el.style.animationDelay = (index * 0.05) + 's';
            });
        });
    </script>
</body>
</html>