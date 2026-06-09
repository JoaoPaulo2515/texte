<?php
// escola/suporte/manuais.php - Central de Manuais e Documentação

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

// Filtros
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todos';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$success = '';
$error = '';

// Admin: Adicionar manual
if (($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_manual'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'sistema';
    $tipo = $_POST['tipo'] ?? 'pdf';
    $url = trim($_POST['url'] ?? '');
    
    if (empty($titulo)) {
        $error = "O título do manual é obrigatório.";
    } elseif (empty($descricao)) {
        $error = "A descrição do manual é obrigatória.";
    } elseif (empty($url) && !isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] != 0) {
        $error = "Forneça um link ou faça upload de um arquivo.";
    } else {
        try {
            $arquivo_nome = null;
            if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] == 0) {
                $extensoes_permitidas = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'jpg', 'png'];
                $extensao = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
                if (in_array($extensao, $extensoes_permitidas)) {
                    $diretorio = __DIR__ . '/../../uploads/manuais/';
                    if (!file_exists($diretorio)) mkdir($diretorio, 0777, true);
                    $arquivo_nome = 'manual_' . time() . '_' . uniqid() . '.' . $extensao;
                    move_uploaded_file($_FILES['arquivo']['tmp_name'], $diretorio . $arquivo_nome);
                    $url = '../../uploads/manuais/' . $arquivo_nome;
                } else {
                    $error = "Formato de arquivo não permitido.";
                }
            }
            
            if (empty($error)) {
                $sql = "INSERT INTO manuais (titulo, descricao, categoria, tipo, url, arquivo, escola_id, ativo, created_at) 
                        VALUES (:titulo, :descricao, :categoria, :tipo, :url, :arquivo, :escola_id, 1, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':categoria' => $categoria,
                    ':tipo' => $tipo,
                    ':url' => $url,
                    ':arquivo' => $arquivo_nome,
                    ':escola_id' => $escola_id
                ]);
                $success = "Manual adicionado com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao adicionar manual: " . $e->getMessage();
        }
    }
}

// Admin: Editar manual
if (($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_manual'])) {
    $manual_id = (int)$_POST['manual_id'];
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'sistema';
    $tipo = $_POST['tipo'] ?? 'pdf';
    $url = trim($_POST['url'] ?? '');
    
    if (empty($titulo)) {
        $error = "O título é obrigatório.";
    } elseif (empty($descricao)) {
        $error = "A descrição é obrigatória.";
    } else {
        try {
            $sql = "UPDATE manuais SET titulo = :titulo, descricao = :descricao, categoria = :categoria, tipo = :tipo, url = :url, updated_at = NOW() 
                    WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':categoria' => $categoria,
                ':tipo' => $tipo,
                ':url' => $url,
                ':id' => $manual_id,
                ':escola_id' => $escola_id
            ]);
            $success = "Manual atualizado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao atualizar manual: " . $e->getMessage();
        }
    }
}

// Admin: Excluir manual
if (($is_admin || $is_suporte) && isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    $manual_id = (int)$_GET['excluir'];
    try {
        $sql = "SELECT arquivo FROM manuais WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $manual_id, ':escola_id' => $escola_id]);
        $manual = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($manual && $manual['arquivo']) {
            $arquivo_path = __DIR__ . '/../../uploads/manuais/' . $manual['arquivo'];
            if (file_exists($arquivo_path)) unlink($arquivo_path);
        }
        
        $sql = "DELETE FROM manuais WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $manual_id, ':escola_id' => $escola_id]);
        $success = "Manual excluído com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao excluir manual: " . $e->getMessage();
    }
}

// Admin: Alternar status
if (($is_admin || $is_suporte) && isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $manual_id = (int)$_GET['toggle'];
    try {
        $sql = "UPDATE manuais SET ativo = NOT ativo WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $manual_id, ':escola_id' => $escola_id]);
        $success = "Status alterado com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao alterar status: " . $e->getMessage();
    }
}

// Registrar download
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $manual_id = (int)$_GET['download'];
    $sql = "UPDATE manuais SET downloads = downloads + 1 WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $manual_id]);
}

// Buscar manuais
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if (!$is_admin && !$is_suporte) {
    $where_conditions[] = "ativo = 1";
}

if ($categoria_filtro != 'todos') {
    $where_conditions[] = "categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}

if (!empty($busca)) {
    $where_conditions[] = "(titulo LIKE :busca OR descricao LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_conditions[] = "escola_id = :escola_id";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql_manuais = "SELECT * FROM manuais $where_sql ORDER BY ordem ASC, downloads DESC, id ASC";
$stmt_manuais = $conn->prepare($sql_manuais);
$stmt_manuais->execute($params);
$manuais = $stmt_manuais->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por categoria
$manuais_por_categoria = [];
$categorias = [
    'sistema' => ['nome' => 'Sistema', 'icone' => 'fas fa-desktop', 'cor' => 'primary', 'descricao' => 'Manuais sobre o funcionamento geral do sistema'],
    'notas' => ['nome' => 'Lançamento de Notas', 'icone' => 'fas fa-edit', 'cor' => 'success', 'descricao' => 'Guia para lançamento e gestão de notas'],
    'matricula' => ['nome' => 'Matrículas', 'icone' => 'fas fa-user-graduate', 'cor' => 'info', 'descricao' => 'Processos de matrícula e rematrícula'],
    'financeiro' => ['nome' => 'Financeiro', 'icone' => 'fas fa-money-bill-wave', 'cor' => 'danger', 'descricao' => 'Gestão financeira e pagamentos'],
    'relatorios' => ['nome' => 'Relatórios', 'icone' => 'fas fa-chart-bar', 'cor' => 'warning', 'descricao' => 'Emissão de relatórios e boletins'],
    'admin' => ['nome' => 'Administração', 'icone' => 'fas fa-user-shield', 'cor' => 'dark', 'descricao' => 'Manuais para administradores do sistema']
];

foreach ($manuais as $manual) {
    $cat = $manual['categoria'];
    if (!isset($manuais_por_categoria[$cat])) {
        $manuais_por_categoria[$cat] = [];
    }
    $manuais_por_categoria[$cat][] = $manual;
}

// Estatísticas
$total_manuais = count($manuais);
$total_downloads = array_sum(array_column($manuais, 'downloads'));

// Funções auxiliares
function getCategoriaInfo($categoria) {
    global $categorias;
    return $categorias[$categoria] ?? ['nome' => ucfirst($categoria), 'icone' => 'fas fa-folder', 'cor' => 'secondary', 'descricao' => ''];
}

function getTipoIcone($tipo) {
    switch ($tipo) {
        case 'pdf': return '<i class="fas fa-file-pdf text-danger"></i>';
        case 'doc': case 'docx': return '<i class="fas fa-file-word text-primary"></i>';
        case 'xls': case 'xlsx': return '<i class="fas fa-file-excel text-success"></i>';
        case 'ppt': case 'pptx': return '<i class="fas fa-file-powerpoint text-warning"></i>';
        case 'video': return '<i class="fas fa-file-video text-info"></i>';
        case 'link': return '<i class="fas fa-link text-primary"></i>';
        default: return '<i class="fas fa-file-alt text-secondary"></i>';
    }
}

function getStatusBadge($ativo) {
    return $ativo == 1 ? '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Ativo</span>' : '<span class="badge bg-secondary"><i class="fas fa-ban"></i> Inativo</span>';
}

function formatarData($data) {
    return $data ? date('d/m/Y', strtotime($data)) : '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Manuais e Documentação | SIGE Angola</title>
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
            content: '📚';
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
           STATS CARDS
        ============================================ */
        .stats-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            margin-bottom: 25px;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stats-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
        }

        .stats-card.primary .stats-number { color: var(--primary-green); }
        .stats-card.success .stats-number { color: var(--success); }

        /* ============================================
           SEARCH CARD
        ============================================ */
        .search-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }

        .search-box {
            max-width: 500px;
            margin: 0 auto;
        }

        .search-box .input-group {
            border-radius: 50px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .search-box .form-control {
            border: none;
            padding: 12px 20px;
        }

        .search-box .form-control:focus {
            box-shadow: none;
        }

        /* ============================================
           CATEGORIA CARD
        ============================================ */
        .categoria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 35px;
        }

        .categoria-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .categoria-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
            transform: scaleX(0);
            transition: var(--transition);
        }

        .categoria-card:hover::before {
            transform: scaleX(1);
        }

        .categoria-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .categoria-card.active {
            background: var(--primary-gradient);
            color: white;
        }

        .categoria-card.active .categoria-icon {
            color: white;
        }

        .categoria-icon {
            font-size: 2.5rem;
            margin-bottom: 12px;
            color: var(--primary-green);
            transition: var(--transition);
        }

        .categoria-nome {
            font-weight: 700;
            margin-bottom: 5px;
        }

        .categoria-count {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        /* ============================================
           MANUAL CARD
        ============================================ */
        .manual-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }

        .manual-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
        }

        .manual-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
        }

        .admin-actions {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 10;
            display: flex;
            gap: 5px;
        }

        .admin-actions .btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            transition: var(--transition);
        }

        .admin-actions .btn:hover {
            transform: scale(1.1);
        }

        .manual-icon {
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-size: 3rem;
        }

        .manual-body {
            padding: 20px;
        }

        .manual-titulo {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: #212529;
        }

        .manual-desc {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 15px;
            line-height: 1.4;
        }

        .manual-footer {
            padding: 15px 20px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .download-count {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .btn-manual {
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-manual:hover {
            transform: translateY(-2px);
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

        .badge.bg-primary { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%) !important; }
        .badge.bg-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important; }
        .badge.bg-info { background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%) !important; }
        .badge.bg-danger { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important; }
        .badge.bg-warning { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%) !important; color: #212529; }
        .badge.bg-dark { background: linear-gradient(135deg, #343a40 0%, #212529 100%) !important; }

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
            .btn-ajuda .tooltip-text {
                display: none;
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
            .categoria-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .manual-grid {
                grid-template-columns: 1fr;
            }
            
            .manual-icon {
                font-size: 2rem;
                padding: 20px;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-ajuda, .search-card, .admin-actions,
            .menu-toggle, .sidebar, .btn-manual {
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
            
            .manual-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <!-- Botão Menu Toggle para Mobile -->
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-book-open me-2"></i> Manuais e Documentação</h2>
                    <p>Central de manuais, guias e documentação do sistema</p>
                </div>
                <div class="no-print">
                    <a href="../dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <?php if ($is_admin || $is_suporte): ?>
                        <button type="button" class="btn btn-light ms-2" data-bs-toggle="modal" data-bs-target="#modalAdicionarManual" style="background: white; color: var(--primary-green); border-radius: 50px; padding: 10px 24px; font-weight: 600;">
                            <i class="fas fa-plus me-2"></i> Adicionar Manual
                        </button>
                    <?php endif; ?>
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
        
        <!-- Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="stats-card primary slide-in-left delay-1">
                    <div class="stats-number"><?php echo $total_manuais; ?></div>
                    <div class="stats-label">Manuais Disponíveis</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stats-card success slide-in-right delay-1">
                    <div class="stats-number"><?php echo number_format($total_downloads); ?></div>
                    <div class="stats-label">Total de Downloads</div>
                </div>
            </div>
        </div>
        
        <!-- Busca -->
        <div class="search-card fade-in-up">
            <form method="GET" class="search-box">
                <div class="input-group">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar manuais..." value="<?php echo htmlspecialchars($busca); ?>">
                    <input type="hidden" name="categoria" value="<?php echo $categoria_filtro; ?>">
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                    <?php if (!empty($busca) || $categoria_filtro != 'todos'): ?>
                        <a href="manuais.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <!-- Categorias -->
        <div class="categoria-grid fade-in-up">
            <div class="categoria-card <?php echo $categoria_filtro == 'todos' ? 'active' : ''; ?>" onclick="filtrarCategoria('todos')">
                <div class="categoria-icon"><i class="fas fa-list-ul"></i></div>
                <div class="categoria-nome">Todos</div>
                <div class="categoria-count"><?php echo $total_manuais; ?> manuais</div>
            </div>
            <?php foreach ($categorias as $key => $cat): ?>
                <?php $count = isset($manuais_por_categoria[$key]) ? count($manuais_por_categoria[$key]) : 0; ?>
                <div class="categoria-card <?php echo $categoria_filtro == $key ? 'active' : ''; ?>" onclick="filtrarCategoria('<?php echo $key; ?>')">
                    <div class="categoria-icon"><i class="<?php echo $cat['icone']; ?>"></i></div>
                    <div class="categoria-nome"><?php echo $cat['nome']; ?></div>
                    <div class="categoria-count"><?php echo $count; ?> manuais</div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Lista de Manuais -->
        <?php if (empty($manuais)): ?>
            <div class="card fade-in-up">
                <div class="card-body text-center py-5">
                    <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                    <h4>Nenhum manual encontrado</h4>
                    <p>Não há manuais disponíveis nesta categoria.</p>
                    <?php if ($is_admin || $is_suporte): ?>
                        <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalAdicionarManual">
                            <i class="fas fa-plus"></i> Adicionar Primeiro Manual
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="manual-grid">
                <?php foreach ($manuais as $index => $manual): ?>
                    <div class="manual-card fade-in-up" style="animation-delay: <?php echo ($index % 6) * 0.05; ?>s">
                        <?php if ($is_admin || $is_suporte): ?>
                            <div class="admin-actions no-print">
                                <button class="btn btn-sm btn-outline-primary" onclick="editarManual(<?php echo $manual['id']; ?>, '<?php echo addslashes($manual['titulo']); ?>', '<?php echo addslashes($manual['descricao']); ?>', '<?php echo $manual['categoria']; ?>', '<?php echo $manual['tipo']; ?>', '<?php echo addslashes($manual['url']); ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="?excluir=<?php echo $manual['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Tem certeza que deseja excluir este manual?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <a href="?toggle=<?php echo $manual['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-<?php echo $manual['ativo'] == 1 ? 'eye-slash' : 'eye'; ?>"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                        <div class="manual-icon">
                            <?php echo getTipoIcone($manual['tipo']); ?>
                        </div>
                        <div class="manual-body">
                            <h5 class="manual-titulo"><?php echo htmlspecialchars($manual['titulo']); ?></h5>
                            <p class="manual-desc"><?php echo htmlspecialchars(substr($manual['descricao'], 0, 100)) . '...'; ?></p>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge bg-<?php echo getCategoriaInfo($manual['categoria'])['cor']; ?>">
                                    <i class="<?php echo getCategoriaInfo($manual['categoria'])['icone']; ?> me-1"></i>
                                    <?php echo getCategoriaInfo($manual['categoria'])['nome']; ?>
                                </span>
                                <?php echo getStatusBadge($manual['ativo']); ?>
                            </div>
                        </div>
                        <div class="manual-footer">
                            <span class="download-count">
                                <i class="fas fa-download me-1"></i> <?php echo number_format($manual['downloads']); ?> downloads
                            </span>
                            <?php if ($manual['ativo'] == 1): ?>
                                <div>
                                    <a href="<?php echo $manual['url']; ?>" class="btn btn-primary-custom btn-manual" target="_blank" onclick="registrarDownload(<?php echo $manual['id']; ?>)">
                                        <i class="fas fa-eye"></i> Visualizar
                                    </a>
                                    <a href="<?php echo $manual['url']; ?>" class="btn btn-success btn-manual" download onclick="registrarDownload(<?php echo $manual['id']; ?>)">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-manual" disabled>
                                    <i class="fas fa-lock"></i> Indisponível
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Adicionar Manual -->
    <?php if ($is_admin || $is_suporte): ?>
    <div class="modal fade" id="modalAdicionarManual" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Adicionar Manual</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Título do Manual <span class="text-danger">*</span></label>
                            <input type="text" name="titulo" class="form-control" required placeholder="Ex: Guia de Lançamento de Notas">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descrição <span class="text-danger">*</span></label>
                            <textarea name="descricao" class="form-control" rows="3" required placeholder="Descreva o conteúdo do manual..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Categoria</label>
                                <select name="categoria" class="form-select">
                                    <option value="sistema">📀 Sistema</option>
                                    <option value="notas">📝 Lançamento de Notas</option>
                                    <option value="matricula">👨‍🎓 Matrículas</option>
                                    <option value="financeiro">💰 Financeiro</option>
                                    <option value="relatorios">📊 Relatórios</option>
                                    <option value="admin">🛡️ Administração</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Tipo de Arquivo</label>
                                <select name="tipo" class="form-select" id="tipo_arquivo" onchange="toggleUrlUpload()">
                                    <option value="pdf">📄 PDF</option>
                                    <option value="doc">📝 Word (DOC)</option>
                                    <option value="xls">📊 Excel (XLS)</option>
                                    <option value="ppt">📽️ PowerPoint (PPT)</option>
                                    <option value="video">🎥 Vídeo</option>
                                    <option value="link">🔗 Link Externo</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3" id="upload_div">
                            <label class="form-label fw-bold">Arquivo</label>
                            <input type="file" name="arquivo" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.mov">
                            <small class="text-muted">Formatos permitidos: PDF, DOC, XLS, PPT, MP4. Máx: 50MB</small>
                        </div>
                        <div class="mb-3" id="url_div" style="display: none;">
                            <label class="form-label fw-bold">URL do Link</label>
                            <input type="url" name="url" class="form-control" placeholder="https://...">
                            <small class="text-muted">Para links externos (YouTube, Vimeo, etc.)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="adicionar_manual" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Manual -->
    <div class="modal fade" id="modalEditarManual" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Editar Manual</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="manual_id" id="edit_manual_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Título</label>
                            <input type="text" name="titulo" id="edit_titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descrição</label>
                            <textarea name="descricao" id="edit_descricao" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Categoria</label>
                                <select name="categoria" id="edit_categoria" class="form-select">
                                    <option value="sistema">Sistema</option>
                                    <option value="notas">Lançamento de Notas</option>
                                    <option value="matricula">Matrículas</option>
                                    <option value="financeiro">Financeiro</option>
                                    <option value="relatorios">Relatórios</option>
                                    <option value="admin">Administração</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Tipo</label>
                                <select name="tipo" id="edit_tipo" class="form-select">
                                    <option value="pdf">PDF</option>
                                    <option value="doc">Word</option>
                                    <option value="xls">Excel</option>
                                    <option value="ppt">PowerPoint</option>
                                    <option value="video">Vídeo</option>
                                    <option value="link">Link</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">URL do Arquivo/Link</label>
                            <input type="text" name="url" id="edit_url" class="form-control" placeholder="Caminho do arquivo ou link">
                            <small class="text-muted">Para trocar o arquivo, adicione um novo manual e remova este</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="editar_manual" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Atualizar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda no-print" id="btnAjuda">
        <i class="fas fa-question"></i>
        <span class="tooltip-text">Precisa de ajuda?</span>
    </button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Ajuda - Central de Manuais</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="ajuda-section">
                        <h5><i class="fas fa-book-open me-2"></i> Sobre a Central de Manuais</h5>
                        <p>Esta é a central de documentação do sistema, onde você encontra todos os manuais, guias e tutoriais disponíveis.</p>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-search me-2"></i> Como encontrar manuais</h5>
                        <ul>
                            <li><strong>Busca:</strong> Digite palavras-chave para encontrar manuais específicos</li>
                            <li><strong>Categorias:</strong> Filtre por tema (Sistema, Notas, Matrículas, etc.)</li>
                            <li><strong>Clique no manual:</strong> Visualize ou faça download do documento</li>
                        </ul>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-download me-2"></i> Tipos de arquivo disponíveis</h5>
                        <ul>
                            <li><i class="fas fa-file-pdf text-danger"></i> <strong>PDF</strong> - Documentos para leitura</li>
                            <li><i class="fas fa-file-word text-primary"></i> <strong>Word</strong> - Documentos editáveis</li>
                            <li><i class="fas fa-file-excel text-success"></i> <strong>Excel</strong> - Planilhas e formulários</li>
                            <li><i class="fas fa-file-powerpoint text-warning"></i> <strong>PowerPoint</strong> - Apresentações</li>
                            <li><i class="fas fa-link text-primary"></i> <strong>Links</strong> - Vídeos tutoriais online</li>
                        </ul>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-shield-alt me-2"></i> Para Administradores</h5>
                        <ul>
                            <li>Adicionar, editar e excluir manuais</li>
                            <li>Ativar/desativar manuais conforme necessidade</li>
                            <li>Acompanhar estatísticas de downloads</li>
                        </ul>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb me-2"></i> <strong>Dica:</strong> Os manuais mais baixados aparecem primeiro na listagem.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="faq.php" class="btn btn-primary-custom"><i class="fas fa-book"></i> Ver FAQ</a>
                    <a href="chamados.php" class="btn btn-info"><i class="fas fa-headset"></i> Abrir Chamado</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
        });
        
        document.getElementById('btnAjuda')?.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('modalAjuda')).show();
        });
        
        function toggleUrlUpload() {
            var tipo = document.getElementById('tipo_arquivo')?.value;
            var uploadDiv = document.getElementById('upload_div');
            var urlDiv = document.getElementById('url_div');
            
            if (tipo === 'link') {
                if (uploadDiv) uploadDiv.style.display = 'none';
                if (urlDiv) urlDiv.style.display = 'block';
            } else {
                if (uploadDiv) uploadDiv.style.display = 'block';
                if (urlDiv) urlDiv.style.display = 'none';
            }
        }
        
        function filtrarCategoria(categoria) {
            const url = new URL(window.location.href);
            if (categoria === 'todos') {
                url.searchParams.delete('categoria');
            } else {
                url.searchParams.set('categoria', categoria);
            }
            window.location.href = url.toString();
        }
        
        function registrarDownload(manualId) {
            fetch('manuais.php?download=' + manualId, { method: 'GET' });
        }
        
        <?php if ($is_admin || $is_suporte): ?>
        function editarManual(id, titulo, descricao, categoria, tipo, url) {
            document.getElementById('edit_manual_id').value = id;
            document.getElementById('edit_titulo').value = titulo;
            document.getElementById('edit_descricao').value = descricao;
            document.getElementById('edit_categoria').value = categoria;
            document.getElementById('edit_tipo').value = tipo;
            document.getElementById('edit_url').value = url;
            new bootstrap.Modal(document.getElementById('modalEditarManual')).show();
        }
        <?php endif; ?>
        
        toggleUrlUpload();
        
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
            
            document.querySelectorAll('.categoria-card, .manual-card, .stats-card, .search-card').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'all 0.6s ease-out';
                observer.observe(el);
            });
        });
    </script>
</body>
</html>