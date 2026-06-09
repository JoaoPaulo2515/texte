<?php
// escola/index.php - Dashboard da Escola com Monitoramento em Tempo Real
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

// ============================================
// VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================

$check = $conn->query("SHOW TABLES LIKE 'sessoes_ativas'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE sessoes_ativas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            usuario_id INT NOT NULL,
            escola_id INT NOT NULL,
            ip VARCHAR(45),
            user_agent TEXT,
            ultima_atividade DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_usuario_escola (usuario_id, escola_id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

$check = $conn->query("SHOW TABLES LIKE 'logs_usuarios'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE logs_usuarios (
            id INT PRIMARY KEY AUTO_INCREMENT,
            usuario_id INT NOT NULL,
            acao VARCHAR(50),
            descricao TEXT,
            ip VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        )
    ");
}

$check = $conn->query("SHOW TABLES LIKE 'escola_parametros_sistema'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_parametros_sistema (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            parametro VARCHAR(100) NOT NULL,
            valor TEXT,
            data_abertura DATETIME,
            data_fechamento DATETIME,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            UNIQUE KEY unique_parametro_escola (parametro, escola_id)
        )
    ");
    
    $stmt = $conn->prepare("INSERT IGNORE INTO escola_parametros_sistema (escola_id, parametro, valor) SELECT id, 'lancamento_notas', 'fechado' FROM escolas");
    $stmt->execute();
}

// ============================================
// PROCESSAR AÇÕES AJAX (TEMPO REAL)
// ============================================

if (isset($_GET['api']) && $_GET['api'] == 'usuarios_ativos') {
    header('Content-Type: application/json');
    
    $stmt = $conn->prepare("
        INSERT INTO sessoes_ativas (usuario_id, escola_id, ip, user_agent, ultima_atividade)
        VALUES (:usuario_id, :escola_id, :ip, :user_agent, NOW())
        ON DUPLICATE KEY UPDATE ultima_atividade = NOW(), ip = :ip, user_agent = :user_agent
    ");
    $stmt->execute([
        ':usuario_id' => $usuario_id,
        ':escola_id' => $escola_id,
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
    
    $stmt = $conn->prepare("
        SELECT sa.*, u.nome, u.email, u.tipo, u.status as usuario_status,
               TIMESTAMPDIFF(MINUTE, sa.ultima_atividade, NOW()) as minutos_inativo
        FROM sessoes_ativas sa
        JOIN usuarios u ON u.id = sa.usuario_id
        WHERE sa.escola_id = :escola_id AND sa.ultima_atividade > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ORDER BY sa.ultima_atividade DESC
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    $usuarios_ativos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total' => count($usuarios_ativos),
        'usuarios' => $usuarios_ativos,
        'timestamp' => date('H:i:s')
    ]);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] == 'estatisticas_usuario') {
    header('Content-Type: application/json');
    $usuario_ver_id = $_GET['usuario_id'] ?? 0;
    
    $stmt = $conn->prepare("
        SELECT u.*, COUNT(DISTINCT sa.id) as sessoes_hoje, MAX(sa.ultima_atividade) as ultimo_acesso
        FROM usuarios u
        LEFT JOIN sessoes_ativas sa ON sa.usuario_id = u.id AND DATE(sa.ultima_atividade) = CURDATE()
        WHERE u.id = :usuario_id AND u.escola_id = :escola_id
        GROUP BY u.id
    ");
    $stmt->execute([':usuario_id' => $usuario_ver_id, ':escola_id' => $escola_id]);
    $estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("SELECT * FROM logs_usuarios WHERE usuario_id = :usuario_id ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([':usuario_id' => $usuario_ver_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'usuario' => $estatisticas, 'logs' => $logs]);
    exit;
}

// ============================================
// ESTATÍSTICAS DO DASHBOARD
// ============================================

$stats = [];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = :escola_id AND tipo_funcionario = 'professor' AND status = 'ativo'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = :escola_id AND status = 'ativo' AND tipo_funcionario != 'professor'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_funcionarios'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_turmas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_disciplinas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("
    SELECT AVG(media_final) as media_geral 
    FROM notas n
    WHERE n.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$stats['media_geral'] = round($stmt->fetch(PDO::FETCH_ASSOC)['media_geral'] ?? 0, 1);

$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados, 
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(*) as total
    FROM notas n
    WHERE n.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$aprovacao = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['taxa_aprovacao'] = $aprovacao['total'] > 0 ? round(($aprovacao['aprovados'] / $aprovacao['total']) * 100, 1) : 0;
$stats['total_aprovados'] = $aprovacao['aprovados'] ?? 0;
$stats['total_reprovados'] = $aprovacao['reprovados'] ?? 0;
$stats['total_recuperacao'] = $aprovacao['recuperacao'] ?? 0;

$stmt = $conn->prepare("
    SELECT t.nome as turma_nome, COUNT(m.id) as total
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    GROUP BY t.id ORDER BY t.nome
");
$stmt->execute([':escola_id' => $escola_id]);
$alunos_por_turma = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(data_matricula, '%Y-%m') as mes,
        COUNT(*) as total
    FROM matriculas m
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND m.data_matricula >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(data_matricula, '%Y-%m')
    ORDER BY mes ASC
");
$stmt->execute([':escola_id' => $escola_id]);
$evolucao_mensal = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats['usuarios_por_tipo'] = [
    'alunos' => $stats['total_alunos'],
    'professores' => $stats['total_professores'],
    'funcionarios' => $stats['total_funcionarios']
];

try {
    $stmt = $conn->prepare("SELECT valor FROM escola_parametros_sistema WHERE escola_id = :escola_id AND parametro = 'lancamento_notas'");
    $stmt->execute([':escola_id' => $escola_id]);
    $lancamento = $stmt->fetch(PDO::FETCH_ASSOC);
    $status_notas = $lancamento['valor'] ?? 'fechado';
} catch (PDOException $e) {
    $status_notas = 'fechado';
}

try {
    $check = $conn->query("SHOW TABLES LIKE 'calendario_provas'");
    $tabela_provas = ($check->rowCount() > 0) ? 'calendario_provas' : 'escola_calendario_provas';
    
    $proximas_provas = $conn->prepare("
        SELECT cp.*, d.nome as disciplina_nome, t.nome as turma_nome
        FROM $tabela_provas cp
        LEFT JOIN disciplinas d ON d.id = cp.disciplina_id
        LEFT JOIN turmas t ON t.id = cp.turma_id
        WHERE cp.escola_id = :escola_id AND cp.data_prova >= CURDATE()
        ORDER BY cp.data_prova ASC LIMIT 5
    ");
    $proximas_provas->execute([':escola_id' => $escola_id]);
    $proximas_provas = $proximas_provas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $proximas_provas = [];
}

$aniversariantes = [];
try {
    $stmt = $conn->prepare("
        SELECT e.*, u.nome, u.email, DAY(e.data_nascimento) as dia_aniversario,
               DATE_FORMAT(e.data_nascimento, '%d/%m') as data_aniversario
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        WHERE e.escola_id = :escola_id AND e.data_nascimento IS NOT NULL
            AND MONTH(e.data_nascimento) = MONTH(CURDATE())
        ORDER BY DAY(e.data_nascimento) ASC LIMIT 10
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    $aniversariantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $aniversariantes = [];
}

try {
    $stmt = $conn->prepare("
        INSERT INTO sessoes_ativas (usuario_id, escola_id, ip, user_agent, ultima_atividade)
        VALUES (:usuario_id, :escola_id, :ip, :user_agent, NOW())
        ON DUPLICATE KEY UPDATE ultima_atividade = NOW(), ip = :ip, user_agent = :user_agent
    ");
    $stmt->execute([
        ':usuario_id' => $usuario_id,
        ':escola_id' => $escola_id,
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
} catch (PDOException $e) {}

try {
    $stmt = $conn->prepare("DELETE FROM sessoes_ativas WHERE ultima_atividade < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute();
} catch (PDOException $e) {}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Dashboard | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* ==============================================
           DESIGN MODERNO - IGUAL AOS OUTROS
           ============================================== */
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            margin-bottom: 45px;
            padding: 25px 30px;
            background: #f5f7fb;
            min-height: calc(100vh - 115px);
        }
        
        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 20px;
            padding: 18px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .top-bar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .top-bar h2 {
            font-size: 1.3em;
            font-weight: 700;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin: 0;
        }
        
        .welcome-text {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.7em;
            font-weight: 600;
        }
        
        .status-online {
            background: #d4edda;
            color: #155724;
        }
        
        .status-offline {
            background: #e9ecef;
            color: #6c757d;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .stat-card.alunos::before { background: linear-gradient(90deg, #667eea, #764ba2); }
        .stat-card.professores::before { background: linear-gradient(90deg, #f093fb, #f5576c); }
        .stat-card.funcionarios::before { background: linear-gradient(90deg, #4facfe, #00f2fe); }
        .stat-card.turmas::before { background: linear-gradient(90deg, #43e97b, #38f9d7); }
        .stat-card.disciplinas::before { background: linear-gradient(90deg, #fa709a, #fee140); }
        .stat-card.media::before { background: linear-gradient(90deg, #a18cd1, #fbc2eb); }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px -12px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 28px;
        }
        
        .stat-card.alunos .stat-icon { background: linear-gradient(135deg, #667eea20, #764ba220); color: #667eea; }
        .stat-card.professores .stat-icon { background: linear-gradient(135deg, #f093fb20, #f5576c20); color: #f5576c; }
        .stat-card.funcionarios .stat-icon { background: linear-gradient(135deg, #4facfe20, #00f2fe20); color: #4facfe; }
        .stat-card.turmas .stat-icon { background: linear-gradient(135deg, #43e97b20, #38f9d720); color: #43e97b; }
        .stat-card.disciplinas .stat-icon { background: linear-gradient(135deg, #fa709a20, #fee14020); color: #fa709a; }
        .stat-card.media .stat-icon { background: linear-gradient(135deg, #a18cd120, #fbc2eb20); color: #a18cd1; }
        
        .stat-value {
            font-size: 2em;
            font-weight: 800;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 0.75em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-trend {
            font-size: 0.7em;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        /* Cards de Conteúdo */
        .content-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .content-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -12px rgba(0,0,0,0.15);
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .card-footer-custom {
            background: #f8f9fa;
            padding: 10px 15px;
            border-top: 1px solid #e9ecef;
        }
        
        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            padding: 15px;
        }
        
        .chart-container-sm {
            position: relative;
            height: 250px;
            width: 100%;
            padding: 15px;
        }
        
        /* Usuários Ativos */
        .user-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .user-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .online-indicator {
            width: 10px;
            height: 10px;
            background: #28a745;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }
        
        /* Eventos */
        .event-item {
            padding: 12px 15px;
            border-left: 4px solid;
            margin-bottom: 8px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .event-item:hover {
            transform: translateX(5px);
            background: #e9ecef;
        }
        
        .event-item.prova { border-left-color: #dc3545; }
        .event-item.prova .event-icon { color: #dc3545; }
        
        /* Aniversariantes */
        .aniversariante-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 8px;
            transition: all 0.3s;
        }
        
        .aniversariante-item:hover {
            transform: translateX(5px);
            background: #e9ecef;
        }
        
        .aniversario-data {
            background: linear-gradient(135deg, #ffecd2, #fcb69f);
            color: #333;
            padding: 5px 10px;
            border-radius: 10px;
            font-weight: bold;
            font-size: 0.75em;
            margin-left: auto;
        }
        
        /* Badges */
        .badge-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7em;
            font-weight: 500;
        }
        
        .status-ativo { background: #d4edda; color: #155724; }
        .status-bloqueado { background: #f8d7da; color: #721c24; }
        
        /* Menu Toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 18px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 14px;
            cursor: pointer;
            font-size: 1.2em;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            transition: all 0.3s;
        }
        
        .menu-toggle:hover { transform: scale(1.05); }
        
        /* Botão Refresh */
        .btn-refresh {
            background: #f8f9fa;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            color: #006B3E;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-refresh:hover {
            background: #006B3E;
            color: white;
            transform: rotate(180deg);
        }
        
        /* Modal */
        .modal-content {
            border-radius: 20px;
            border: none;
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            border: none;
        }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
            .menu-toggle { display: block; }
            .top-bar { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; }
            .stat-value { font-size: 1.5em; }
            .stat-icon { width: 45px; height: 45px; font-size: 20px; }
        }
        
        @media (max-width: 480px) {
            .main-content { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr; }
            .chart-container { height: 250px; }
            .chart-container-sm { height: 200px; }
        }
        
        /* Scrollbar Personalizada */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #006B3E; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #004d2d; }
    </style>
</head>
<body>

<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>

<?php include 'menu_escola.php'; ?>

<div class="main-content">
    <div class="top-bar">
        <div>
            <h2><i class="fas fa-chart-line me-2" style="color: #006B3E;"></i> Dashboard</h2>
            <p class="welcome-text">Bem-vindo(a) de volta, <?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?>! 👋</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="status-badge <?php echo $status_notas == 'aberto' ? 'status-online' : 'status-offline'; ?>">
                <i class="fas fa-<?php echo $status_notas == 'aberto' ? 'lock-open' : 'lock'; ?>"></i>
                Notas: <?php echo $status_notas == 'aberto' ? 'ABERTO' : 'FECHADO'; ?>
            </span>
            <button class="btn-refresh" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i>
            </button>
            <div class="dropdown">
                <span class="dropdown-toggle" data-bs-toggle="dropdown" style="cursor: pointer;">
                    <i class="fas fa-user-circle fa-2x text-success"></i>
                </span>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="perfil.php"><i class="fas fa-user me-2"></i> Meu Perfil</a></li>
                    <li><a class="dropdown-item" href="configuracoes.php"><i class="fas fa-cog me-2"></i> Configurações</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Sair</a></li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card alunos" onclick="window.location.href='alunos/index.php'">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_alunos']); ?></div>
            <div class="stat-label">Total de Alunos</div>
            <div class="stat-trend"><i class="fas fa-arrow-up text-success"></i> +5% este mês</div>
        </div>
        <div class="stat-card professores" onclick="window.location.href='professores/index.php'">
            <div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_professores']); ?></div>
            <div class="stat-label">Professores</div>
            <div class="stat-trend"><i class="fas fa-user-plus text-success"></i> 2 novos</div>
        </div>
        <div class="stat-card funcionarios" onclick="window.location.href='funcionarios/index.php'">
            <div class="stat-icon"><i class="fas fa-users-gear"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_funcionarios']); ?></div>
            <div class="stat-label">Funcionários</div>
            <div class="stat-trend"><i class="fas fa-chart-line text-info"></i> Estável</div>
        </div>
        <div class="stat-card turmas" onclick="window.location.href='turmas/index.php'">
            <div class="stat-icon"><i class="fas fa-users-group"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_turmas']); ?></div>
            <div class="stat-label">Turmas Ativas</div>
            <div class="stat-trend"><i class="fas fa-plus-circle text-success"></i> Média <?php echo round($stats['total_alunos'] / max($stats['total_turmas'], 1), 1); ?> alunos/turma</div>
        </div>
        <div class="stat-card disciplinas" onclick="window.location.href='disciplinas/index.php'">
            <div class="stat-icon"><i class="fas fa-book"></i></div>
            <div class="stat-value"><?php echo number_format($stats['total_disciplinas']); ?></div>
            <div class="stat-label">Disciplinas</div>
            <div class="stat-trend"><i class="fas fa-chart-line text-info"></i> Currículo completo</div>
        </div>
        <div class="stat-card media">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-value"><?php echo $stats['media_geral']; ?></div>
            <div class="stat-label">Média Geral</div>
            <div class="stat-trend"><i class="fas fa-arrow-up text-success"></i> +0.5 pontos</div>
        </div>
    </div>
    
    <!-- Gráficos -->
    <div class="row">
        <div class="col-md-8">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-chart-line me-2" style="color: #006B3E;"></i> Evolução de Matrículas (Últimos 6 Meses)
                </div>
                <div class="card-body p-0">
                    <div class="chart-container">
                        <canvas id="evolucaoChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-chart-pie me-2" style="color: #006B3E;"></i> Distribuição por Tipo
                </div>
                <div class="card-body p-0">
                    <div class="chart-container-sm">
                        <canvas id="distribuicaoChart"></canvas>
                    </div>
                    <div class="text-center pb-3">
                        <div class="row">
                            <div class="col-4"><small class="text-muted">Alunos</small><br><strong><?php echo $stats['total_alunos']; ?></strong></div>
                            <div class="col-4"><small class="text-muted">Professores</small><br><strong><?php echo $stats['total_professores']; ?></strong></div>
                            <div class="col-4"><small class="text-muted">Funcionários</small><br><strong><?php echo $stats['total_funcionarios']; ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-4">
            <div class="content-card">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-users-viewfinder me-2" style="color: #17a2b8;"></i> Usuários Ativos</span>
                    <span><small class="text-muted"><i class="fas fa-sync-alt"></i> <span id="ultimaAtualizacao">--:--:--</span></small></span>
                </div>
                <div class="card-body p-0" id="usuariosAtivosList" style="max-height: 400px; overflow-y: auto;">
                    <div class="text-center text-muted p-4">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2"></i>
                        <p>Carregando usuários ativos...</p>
                    </div>
                </div>
                <div class="card-footer-custom text-center">
                    <small class="text-muted">Total: <span id="totalAtivos">0</span> usuários online</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-calendar-alt me-2" style="color: #dc3545;"></i> Próximas Provas
                </div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <?php if (!empty($proximas_provas)): ?>
                        <?php foreach ($proximas_provas as $prova): ?>
                        <div class="event-item prova">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="event-icon d-inline-block me-2"><i class="fas fa-calendar-day"></i></div>
                                    <strong><?php echo date('d/m/Y', strtotime($prova['data_prova'])); ?></strong>
                                    <h6 class="mb-1 mt-1"><?php echo htmlspecialchars($prova['titulo'] ?? 'Prova'); ?></h6>
                                    <small class="text-muted"><i class="fas fa-book"></i> <?php echo $prova['disciplina_nome'] ?? 'N/A'; ?></small><br>
                                    <small class="text-muted"><i class="fas fa-users"></i> <?php echo $prova['turma_nome'] ?? 'N/A'; ?></small>
                                </div>
                                <?php
                                $dias_restantes = (strtotime($prova['data_prova']) - time()) / 86400;
                                $dias_restantes = round($dias_restantes);
                                $cor_dias = $dias_restantes <= 3 ? 'text-danger' : ($dias_restantes <= 7 ? 'text-warning' : 'text-success');
                                ?>
                                <div class="text-end">
                                    <span class="badge bg-secondary"><?php echo $dias_restantes; ?> dias</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted p-4">
                            <i class="fas fa-calendar-times fa-2x mb-2"></i>
                            <p>Nenhuma prova agendada</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-birthday-cake me-2" style="color: #ff9800;"></i> Aniversariantes do Mês
                </div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <?php if (!empty($aniversariantes)): ?>
                        <?php foreach ($aniversariantes as $aniversariante): ?>
                        <div class="aniversariante-item">
                            <div class="user-avatar me-3" style="width: 40px; height: 40px; font-size: 1em;">
                                <?php echo strtoupper(substr($aniversariante['nome'], 0, 1)); ?>
                            </div>
                            <div class="flex-grow-1">
                                <strong><?php echo htmlspecialchars($aniversariante['nome']); ?></strong>
                                <br><small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                            </div>
                            <div class="aniversario-data">
                                <i class="fas fa-gift me-1"></i> <?php echo date('d/m', strtotime($aniversariante['data_nascimento'])); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted p-4">
                            <i class="fas fa-birthday-cake fa-2x mb-2"></i>
                            <p>Nenhum aniversariante este mês</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Gráfico de Alunos por Turma -->
    <div class="row">
        <div class="col-md-12">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-chart-bar me-2" style="color: #006B3E;"></i> Distribuição de Alunos por Turma
                </div>
                <div class="card-body p-0">
                    <div class="chart-container">
                        <canvas id="turmasChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Visualizar Usuário -->
<div class="modal fade" id="modalVisualizarUsuario" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user"></i> Detalhes do Usuário</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalUsuarioContent">
                <div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i> Carregando...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Menu Toggle
    document.getElementById('menuToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });
    
    // Gráfico de Evolução (Linha)
    const mesesEvolucao = <?php 
        $meses = [];
        $valores = [];
        foreach ($evolucao_mensal as $item) {
            $meses[] = date('M/Y', strtotime($item['mes'] . '-01'));
            $valores[] = $item['total'];
        }
        echo json_encode($meses);
    ?>;
    const valoresEvolucao = <?php echo json_encode($valores); ?>;
    
    const ctxEvolucao = document.getElementById('evolucaoChart').getContext('2d');
    new Chart(ctxEvolucao, {
        type: 'line',
        data: {
            labels: mesesEvolucao,
            datasets: [{
                label: 'Novas Matrículas',
                data: valoresEvolucao,
                borderColor: '#006B3E',
                backgroundColor: 'rgba(0, 107, 62, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#006B3E',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: { callbacks: { label: function(context) { return 'Matrículas: ' + context.raw; } } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, title: { display: true, text: 'Número de Matrículas' } },
                x: { title: { display: true, text: 'Mês' } }
            }
        }
    });
    
    // Gráfico de Distribuição (Pizza)
    const ctxDistribuicao = document.getElementById('distribuicaoChart').getContext('2d');
    new Chart(ctxDistribuicao, {
        type: 'doughnut',
        data: {
            labels: ['Alunos', 'Professores', 'Funcionários'],
            datasets: [{
                data: [<?php echo $stats['total_alunos']; ?>, <?php echo $stats['total_professores']; ?>, <?php echo $stats['total_funcionarios']; ?>],
                backgroundColor: ['#667eea', '#f093fb', '#4facfe'],
                borderWidth: 0,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw; } } }
            }
        }
    });
    
    // Gráfico de Alunos por Turma (Barra)
    const turmasLabels = <?php echo json_encode(array_column($alunos_por_turma, 'turma_nome')); ?>;
    const turmasValues = <?php echo json_encode(array_column($alunos_por_turma, 'total')); ?>;
    
    const ctxTurmas = document.getElementById('turmasChart').getContext('2d');
    new Chart(ctxTurmas, {
        type: 'bar',
        data: {
            labels: turmasLabels,
            datasets: [{
                label: 'Alunos',
                data: turmasValues,
                backgroundColor: '#006B3E',
                borderRadius: 8,
                barPercentage: 0.7,
                categoryPercentage: 0.8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: { callbacks: { label: function(context) { return 'Alunos: ' + context.raw; } } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, title: { display: true, text: 'Número de Alunos' } },
                x: { ticks: { autoSkip: false, rotation: 45, font: { size: 10 } } }
            }
        }
    });
    
    // Usuários ativos em tempo real
    function carregarUsuariosAtivos() {
        fetch('index.php?api=usuarios_ativos')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('totalAtivos').textContent = data.total;
                    document.getElementById('ultimaAtualizacao').textContent = data.timestamp;
                    let html = '';
                    if (data.total > 0) {
                        data.usuarios.forEach(usuario => {
                            let onlineClass = usuario.minutos_inativo < 1 ? 'status-online' : 'status-offline';
                            let onlineText = usuario.minutos_inativo < 1 ? 'Online agora' : `${usuario.minutos_inativo} min atrás`;
                            let statusClass = usuario.usuario_status == 'ativo' ? 'status-ativo' : 'status-bloqueado';
                            let corAvatar = usuario.tipo == 'professor' ? '#f093fb' : (usuario.tipo == 'aluno' ? '#667eea' : '#4facfe');
                            html += `<div class="user-item" onclick="visualizarUsuario(${usuario.usuario_id})">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="user-avatar" style="background: linear-gradient(135deg, ${corAvatar}, ${corAvatar}aa);">${usuario.nome.charAt(0).toUpperCase()}</div>
                                    <div>
                                        <strong>${usuario.nome}</strong><br>
                                        <small class="text-muted"><i class="fas fa-envelope"></i> ${usuario.email}</small><br>
                                        <small class="text-muted"><i class="fas fa-tag"></i> ${usuario.tipo == 'professor' ? 'Professor' : (usuario.tipo == 'aluno' ? 'Aluno' : 'Funcionário')}</small>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="badge-status ${onlineClass}"><span class="online-indicator"></span> ${onlineText}</span><br>
                                    <span class="badge-status ${statusClass} mt-1">${usuario.usuario_status == 'ativo' ? 'Ativo' : 'Bloqueado'}</span>
                                </div>
                            </div>`;
                        });
                    } else {
                        html = '<div class="text-center text-muted p-4"><i class="fas fa-user-slash fa-2x mb-2"></i><p>Nenhum usuário ativo no momento</p></div>';
                    }
                    document.getElementById('usuariosAtivosList').innerHTML = html;
                }
            })
            .catch(() => {
                document.getElementById('usuariosAtivosList').innerHTML = '<div class="text-center text-danger p-4"><i class="fas fa-exclamation-triangle"></i> Erro ao carregar dados</div>';
            });
    }
    
    function visualizarUsuario(usuarioId) {
        fetch(`index.php?api=estatisticas_usuario&usuario_id=${usuarioId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let usuario = data.usuario, logs = data.logs;
                    let logsHtml = '';
                    if (logs.length > 0) {
                        logs.forEach(log => { logsHtml += `<div class="border-bottom pb-2 mb-2"><small class="text-muted">${log.created_at}</small><p class="mb-0">${log.descricao || log.acao}</p></div>`; });
                    } else { logsHtml = '<p class="text-muted text-center">Nenhum registro encontrado</p>'; }
                    let html = `<div class="row">
                        <div class="col-md-4 text-center">
                            <div class="user-avatar mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2em;">${usuario.nome.charAt(0).toUpperCase()}</div>
                            <h5>${usuario.nome}</h5>
                            <p class="text-muted">${usuario.email}</p>
                            <span class="badge-status ${usuario.status == 'ativo' ? 'status-ativo' : 'status-bloqueado'}">${usuario.status == 'ativo' ? 'Ativo' : 'Bloqueado'}</span>
                        </div>
                        <div class="col-md-8">
                            <h6><i class="fas fa-chart-line"></i> Estatísticas</h6>
                            <div class="row mb-3">
                                <div class="col-6"><div class="bg-light p-3 rounded text-center"><strong>Sessões hoje</strong><br><span class="h3">${usuario.sessoes_hoje || 0}</span></div></div>
                                <div class="col-6"><div class="bg-light p-3 rounded text-center"><strong>Último acesso</strong><br><span class="small">${usuario.ultimo_acesso ? new Date(usuario.ultimo_acesso).toLocaleString() : 'Nunca'}</span></div></div>
                            </div>
                            <h6><i class="fas fa-history"></i> Últimas Atividades</h6>
                            <div style="max-height: 200px; overflow-y: auto;">${logsHtml}</div>
                        </div>
                    </div>`;
                    document.getElementById('modalUsuarioContent').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('modalVisualizarUsuario')).show();
                }
            });
    }
    
    carregarUsuariosAtivos();
    setInterval(carregarUsuariosAtivos, 10000);
</script>
</body>
</html>