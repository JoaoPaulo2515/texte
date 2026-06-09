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

// Verificar se a tabela sessoes_ativas existe
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

// Verificar se a tabela logs_usuarios existe
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

// Verificar se a tabela escola_parametros_sistema existe e tem as colunas corretas
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
    
    // Inserir parâmetro padrão
    $stmt = $conn->prepare("
        INSERT IGNORE INTO escola_parametros_sistema (escola_id, parametro, valor) 
        SELECT id, 'lancamento_notas', 'fechado' FROM escolas
    ");
    $stmt->execute();
}

// ============================================
// PROCESSAR AÇÕES AJAX (TEMPO REAL)
// ============================================

// API para buscar usuários ativos
if (isset($_GET['api']) && $_GET['api'] == 'usuarios_ativos') {
    header('Content-Type: application/json');
    
    // Atualizar último acesso do usuário atual
    $stmt = $conn->prepare("
        INSERT INTO sessoes_ativas (usuario_id, escola_id, ip, user_agent, ultima_atividade)
        VALUES (:usuario_id, :escola_id, :ip, :user_agent, NOW())
        ON DUPLICATE KEY UPDATE 
        ultima_atividade = NOW(),
        ip = :ip,
        user_agent = :user_agent
    ");
    $stmt->execute([
        ':usuario_id' => $usuario_id,
        ':escola_id' => $escola_id,
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
    
    // Buscar usuários ativos (últimos 5 minutos)
    $stmt = $conn->prepare("
        SELECT 
            sa.*,
            u.nome,
            u.email,
            u.tipo,
            u.status as usuario_status,
            TIMESTAMPDIFF(MINUTE, sa.ultima_atividade, NOW()) as minutos_inativo
        FROM sessoes_ativas sa
        JOIN usuarios u ON u.id = sa.usuario_id
        WHERE sa.escola_id = :escola_id 
            AND sa.ultima_atividade > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
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

// API para bloquear/desbloquear usuário
if (isset($_POST['api']) && $_POST['api'] == 'bloquear_usuario') {
    header('Content-Type: application/json');
    $usuario_bloquear_id = $_POST['usuario_id'] ?? 0;
    $acao = $_POST['acao'] ?? 'bloquear';
    
    $novo_status = ($acao == 'bloquear') ? 'bloqueado' : 'ativo';
    
    $stmt = $conn->prepare("
        UPDATE usuarios 
        SET status = :status
        WHERE id = :usuario_id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':status' => $novo_status,
        ':usuario_id' => $usuario_bloquear_id,
        ':escola_id' => $escola_id
    ]);
    
    // Registrar log
    $stmt = $conn->prepare("
        INSERT INTO logs_usuarios (usuario_id, acao, descricao, ip, created_at)
        VALUES (:usuario_id, :acao, :descricao, :ip, NOW())
    ");
    $stmt->execute([
        ':usuario_id' => $usuario_id,
        ':acao' => $acao,
        ':descricao' => "Usuário " . ($acao == 'bloquear' ? 'bloqueado' : 'desbloqueado') . " ID: $usuario_bloquear_id",
        ':ip' => $_SERVER['REMOTE_ADDR']
    ]);
    
    echo json_encode(['success' => true, 'status' => $novo_status]);
    exit;
}

// API para buscar estatísticas do usuário
if (isset($_GET['api']) && $_GET['api'] == 'estatisticas_usuario') {
    header('Content-Type: application/json');
    $usuario_ver_id = $_GET['usuario_id'] ?? 0;
    
    // Buscar estatísticas do usuário
    $stmt = $conn->prepare("
        SELECT 
            u.*,
            COUNT(DISTINCT sa.id) as sessoes_hoje,
            MAX(sa.ultima_atividade) as ultimo_acesso
        FROM usuarios u
        LEFT JOIN sessoes_ativas sa ON sa.usuario_id = u.id AND DATE(sa.ultima_atividade) = CURDATE()
        WHERE u.id = :usuario_id AND u.escola_id = :escola_id
        GROUP BY u.id
    ");
    $stmt->execute([':usuario_id' => $usuario_ver_id, ':escola_id' => $escola_id]);
    $estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Buscar últimas ações do usuário
    $stmt = $conn->prepare("
        SELECT * FROM logs_usuarios 
        WHERE usuario_id = :usuario_id 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([':usuario_id' => $usuario_ver_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'usuario' => $estatisticas,
        'logs' => $logs
    ]);
    exit;
}

// ============================================
// ESTATÍSTICAS DO DASHBOARD
// ============================================

$stats = [];

// Total de Alunos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de Professores
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM professores WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de Funcionários
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM usuarios WHERE escola_id = :escola_id AND tipo IN ('admin', 'secretaria', 'funcionario')");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_funcionarios'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de Turmas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_turmas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de Disciplinas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_disciplinas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Média Geral de Notas
$stmt = $conn->prepare("
    SELECT AVG(media_final) as media_geral 
    FROM notas n
    JOIN matriculas m ON m.id = n.matricula_id
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$stats['media_geral'] = round($stmt->fetch(PDO::FETCH_ASSOC)['media_geral'] ?? 0, 1);

// Taxa de Aprovação
$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(*) as total
    FROM notas n
    JOIN matriculas m ON m.id = n.matricula_id
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$aprovacao = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['taxa_aprovacao'] = $aprovacao['total'] > 0 ? round(($aprovacao['aprovados'] / $aprovacao['total']) * 100, 1) : 0;

// Alunos por Turma (para gráfico)
$stmt = $conn->prepare("
    SELECT t.nome as turma_nome, COUNT(m.id) as total
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    GROUP BY t.id
    ORDER BY t.nome
");
$stmt->execute([':escola_id' => $escola_id]);
$alunos_por_turma = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status do lançamento de notas (CORRIGIDO)
try {
    $stmt = $conn->prepare("SELECT valor FROM escola_parametros_sistema WHERE escola_id = :escola_id AND parametro = 'lancamento_notas'");
    $stmt->execute([':escola_id' => $escola_id]);
    $lancamento = $stmt->fetch(PDO::FETCH_ASSOC);
    $status_notas = $lancamento['valor'] ?? 'fechado';
} catch (PDOException $e) {
    $status_notas = 'fechado';
}

// Próximas Provas (tabela pode ser calendario_provas ou escola_calendario_provas)
try {
    $check = $conn->query("SHOW TABLES LIKE 'calendario_provas'");
    $tabela_provas = ($check->rowCount() > 0) ? 'calendario_provas' : 'escola_calendario_provas';
    
    $proximas_provas = $conn->prepare("
        SELECT cp.*, d.nome as disciplina_nome, t.nome as turma_nome
        FROM $tabela_provas cp
        LEFT JOIN disciplinas d ON d.id = cp.disciplina_id
        LEFT JOIN turmas t ON t.id = cp.turma_id
        WHERE cp.escola_id = :escola_id AND cp.data_prova >= CURDATE()
        ORDER BY cp.data_prova ASC
        LIMIT 5
    ");
    $proximas_provas->execute([':escola_id' => $escola_id]);
    $proximas_provas = $proximas_provas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $proximas_provas = [];
}

// Aniversariantes do mês
$aniversariantes = [];
try {
    $stmt = $conn->prepare("
        SELECT e.*, u.nome, u.email,
               DAY(e.data_nascimento) as dia_aniversario
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        WHERE e.escola_id = :escola_id 
            AND e.data_nascimento IS NOT NULL
            AND MONTH(e.data_nascimento) = MONTH(CURDATE())
        ORDER BY DAY(e.data_nascimento) ASC
        LIMIT 10
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    $aniversariantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $aniversariantes = [];
}

// Registrar sessão ativa do usuário atual
try {
    $stmt = $conn->prepare("
        INSERT INTO sessoes_ativas (usuario_id, escola_id, ip, user_agent, ultima_atividade)
        VALUES (:usuario_id, :escola_id, :ip, :user_agent, NOW())
        ON DUPLICATE KEY UPDATE 
        ultima_atividade = NOW(),
        ip = :ip,
        user_agent = :user_agent
    ");
    $stmt->execute([
        ':usuario_id' => $usuario_id,
        ':escola_id' => $escola_id,
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
} catch (PDOException $e) {
    // Tabela pode não existir ainda
}

// Limpar sessões inativas (mais de 10 minutos)
try {
    $stmt = $conn->prepare("DELETE FROM sessoes_ativas WHERE ultima_atividade < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute();
} catch (PDOException $e) {
    // Tabela pode não existir ainda
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Estilos mantidos iguais ao original */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.2em;
            margin: 0;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.7;
            margin: 5px 0 0;
        }
        
        .user-info-sidebar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            font-size: 1.1em;
        }
        
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            margin: 0;
            display: none;
        }
        
        .nav-submenu.show {
            display: block;
        }
        
        .nav-submenu .nav-link {
            padding: 8px 25px;
            font-size: 0.9em;
        }
        
        .nav-submenu .nav-link i {
            font-size: 0.9em;
            width: 20px;
        }
        
        .nav-item.has-submenu > .nav-link {
            position: relative;
        }
        
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        
        .nav-item.has-submenu.open > .nav-link:after {
            transform: rotate(180deg);
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
        }
        
        .stat-icon.primary { background: #e3f2fd; color: #1976d2; }
        .stat-icon.success { background: #e8f5e9; color: #388e3c; }
        .stat-icon.warning { background: #fff3e0; color: #f57c00; }
        .stat-icon.info { background: #e0f7fa; color: #0097a7; }
        .stat-icon.danger { background: #ffebee; color: #d32f2f; }
        
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }
        
        .notification-item, .event-item, .user-item {
            border-left: 3px solid #006B3E;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .user-item {
            border-left-color: #17a2b8;
            cursor: pointer;
        }
        
        .user-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: bold;
        }
        .status-aberto { background: #d4edda; color: #155724; }
        .status-fechado { background: #f8d7da; color: #721c24; }
        .status-ativo { background: #d4edda; color: #155724; }
        .status-bloqueado { background: #f8d7da; color: #721c24; }
        .status-online { background: #28a745; color: white; }
        .status-offline { background: #6c757d; color: white; }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #006B3E;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2em;
        }
        
        .pulse {
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
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
        
        .foto-perfil-modal {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #006B3E;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
            <div class="user-info-sidebar mt-2">
                <small><i class="fas fa-user"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></small>
                <br>
                <small><i class="fas fa-building"></i> <?php echo ucfirst($_SESSION['user_role'] ?? 'usuário'); ?></small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <!-- DASHBOARD PRINCIPAL -->
            <li class="nav-item">
                <a href="index.php" class="nav-link active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <!-- SECRETARIA - Gestão Administrativa -->
            <li class="nav-item has-submenu" id="menuSecretaria">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-building"></i>
                    <span>Secretaria</span>
                </a>
                <ul class="nav-submenu" id="submenuSecretaria">
                    <li class="nav-item"><a href="secretaria/lista_alunos.php" class="nav-link"><i class="fas fa-list"></i> Lista de Alunos</a></li>
                    <li class="nav-item"><a href="secretaria/alunos_matriculados.php" class="nav-link"><i class="fas fa-check-circle"></i> Alunos Matriculados</a></li>
                    <li class="nav-item"><a href="secretaria/inscricoes.php" class="nav-link"><i class="fas fa-file-signature"></i> Inscrições</a></li>
                    <li class="nav-item"><a href="secretaria/rematricula.php" class="nav-link"><i class="fas fa-sync-alt"></i> Rematrícula</a></li>
                    <li class="nav-item"><a href="secretaria/matricula.php" class="nav-link"><i class="fas fa-user-plus"></i> Matrícula</a></li>
                </ul>
            </li>
            
            <!-- ACADÉMICO - Gestão de Ensino -->
            <li class="nav-item has-submenu" id="menuAcademico">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Académico</span>
                </a>
                <ul class="nav-submenu" id="submenuAcademico">
                    <li class="nav-item"><a href="alunos/index.php" class="nav-link"><i class="fas fa-users"></i> Alunos</a></li>
                    <li class="nav-item"><a href="professores/index.php" class="nav-link"><i class="fas fa-chalkboard-user"></i> Professores</a></li>
                    <li class="nav-item"><a href="turmas/index.php" class="nav-link"><i class="fas fa-users-group"></i> Turmas</a></li>
                    <li class="nav-item"><a href="disciplinas/index.php" class="nav-link"><i class="fas fa-book"></i> Disciplinas</a></li>
                    <li class="nav-item"><a href="notas/index.php" class="nav-link"><i class="fas fa-edit"></i> Notas</a></li>
                    <li class="nav-item"><a href="chamada/index.php" class="nav-link"><i class="fas fa-calendar-check"></i> Chamada</a></li>
                </ul>
            </li>
            
            <!-- SERVIÇOS PEDAGÓGICOS - Planejamento e Coordenação -->
            <li class="nav-item has-submenu" id="menuServicosPedagogicos">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-chalkboard"></i>
                    <span>Serviços Pedagógicos</span>
                </a>
                <ul class="nav-submenu" id="submenuServicosPedagogicos">
                    <li class="nav-item"><a href="servicos_pedagogicos/gerais/index.php" class="nav-link"><i class="fas fa-cog"></i> Gerais</a></li>
                    <li class="nav-item"><a href="servicos_pedagogicos/disciplina_turma/index.php" class="nav-link"><i class="fas fa-link"></i> Disciplina e Turma</a></li>
                    <li class="nav-item"><a href="servicos_pedagogicos/disciplina_classe/index.php" class="nav-link"><i class="fas fa-layer-group"></i> Disciplina e Classe</a></li>
                    <li class="nav-item"><a href="servicos_pedagogicos/coordenacao/index.php" class="nav-link"><i class="fas fa-users"></i> Coordenação</a></li>
                </ul>
            </li>
            
            <!-- BIBLIOTECA -->
            <li class="nav-item has-submenu" id="menuBiblioteca">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-book-open"></i>
                    <span>Biblioteca</span>
                </a>
                <ul class="nav-submenu" id="submenuBiblioteca">
                    <li class="nav-item"><a href="biblioteca/index.php" class="nav-link"><i class="fas fa-search"></i> Visualizar Acervo</a></li>
                    <li class="nav-item"><a href="biblioteca/cadastrar.php" class="nav-link"><i class="fas fa-plus-circle"></i> Cadastrar Livro</a></li>
                </ul>
            </li>
            
            <!-- RELATÓRIOS -->
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-chart-line"></i>
                    <span>Relatórios</span>
                </a>
                <ul class="nav-submenu" id="submenuRelatoriosEscola">
                    <li class="nav-item"><a href="relatorios/notas.php" class="nav-link"><i class="fas fa-graduation-cap"></i> Relatório de Notas</a></li>
                    <li class="nav-item"><a href="relatorios/frequencia.php" class="nav-link"><i class="fas fa-calendar-check"></i> Relatório de Frequência</a></li>
                    <li class="nav-item"><a href="relatorios/desempenho.php" class="nav-link"><i class="fas fa-chart-line"></i> Análise de Desempenho</a></li>
                    <li class="nav-item"><a href="relatorios/pedagogicos.php" class="nav-link"><i class="fas fa-chalkboard"></i> Relatórios Pedagógicos</a></li>
                </ul>
            </li>
            
            <!-- FINANCEIRO -->
            <li class="nav-item has-submenu" id="menuFinanceiro">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i>
                    <span>Financeiro</span>
                </a>
                <ul class="nav-submenu" id="submenuFinanceiro">
                    <li class="nav-item"><a href="financeiro/mensalidades.php" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
                    <li class="nav-item"><a href="financeiro/extratos.php" class="nav-link"><i class="fas fa-file-invoice"></i> Extratos</a></li>
                    <li class="nav-item"><a href="financeiro/recibos.php" class="nav-link"><i class="fas fa-receipt"></i> Recibos</a></li>
                </ul>
            </li>
            
            <!-- CONFIGURAÇÕES -->
            <li class="nav-item has-submenu" id="menuConfiguracoes">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-cogs"></i>
                    <span>Configurações</span>
                </a>
                <ul class="nav-submenu" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="config/geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="config/banco/index.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="config/pagamento/index.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="config/sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            
            <!-- SAIR -->
            <li class="nav-item">
                <a href="../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sair</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-tachometer-alt"></i> Dashboard</h2>
            <div class="user-menu">
                <span class="status-badge <?php echo $status_notas == 'aberto' ? 'status-aberto' : 'status-fechado'; ?> me-3">
                    <i class="fas fa-<?php echo $status_notas == 'aberto' ? 'lock-open' : 'lock'; ?>"></i>
                    Notas: <?php echo $status_notas == 'aberto' ? 'ABERTO' : 'FECHADO'; ?>
                </span>
                <i class="fas fa-bell"></i>
                <span class="ms-2"><?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></span>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon primary"><i class="fas fa-users"></i></div></div>
                <div class="stat-value"><?php echo number_format($stats['total_alunos']); ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon success"><i class="fas fa-chalkboard-user"></i></div></div>
                <div class="stat-value"><?php echo number_format($stats['total_professores']); ?></div>
                <div class="stat-label">Professores</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon info"><i class="fas fa-users-gear"></i></div></div>
                <div class="stat-value"><?php echo number_format($stats['total_funcionarios']); ?></div>
                <div class="stat-label">Funcionários</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon warning"><i class="fas fa-users-group"></i></div></div>
                <div class="stat-value"><?php echo number_format($stats['total_turmas']); ?></div>
                <div class="stat-label">Turmas Ativas</div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Alunos por Turma</div>
                    <div class="card-body">
                        <canvas id="turmasChart" height="280"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Taxa de Aprovação</div>
                    <div class="card-body text-center">
                        <canvas id="aprovacaoChart" height="180"></canvas>
                        <h3 class="mt-3"><?php echo $stats['taxa_aprovacao']; ?>%</h3>
                        <p class="text-muted">Taxa de aprovação geral</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-users-viewfinder"></i> Usuários Ativos <span class="badge bg-success" id="totalAtivos">0</span></span>
                        <span><small class="text-muted"><i class="fas fa-sync-alt"></i> <span id="ultimaAtualizacao">--:--:--</span></small></span>
                    </div>
                    <div class="card-body" id="usuariosAtivosList" style="max-height: 400px; overflow-y: auto;">
                        <div class="text-center text-muted p-3">
                            <i class="fas fa-spinner fa-spin"></i> Carregando usuários ativos...
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-calendar-alt"></i> Próximas Provas</div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($proximas_provas)): ?>
                            <?php foreach ($proximas_provas as $prova): ?>
                            <div class="event-item">
                                <strong><i class="fas fa-calendar-day"></i> <?php echo date('d/m/Y', strtotime($prova['data_prova'])); ?></strong>
                                <h6 class="mb-1 mt-1"><?php echo htmlspecialchars($prova['titulo'] ?? 'Prova'); ?></h6>
                                <small class="text-muted">
                                    <i class="fas fa-book"></i> <?php echo $prova['disciplina_nome'] ?? 'N/A'; ?> |
                                    <i class="fas fa-users"></i> <?php echo $prova['turma_nome'] ?? 'N/A'; ?>
                                </small>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted">Nenhuma prova agendada</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-birthday-cake"></i> Aniversariantes do Mês</div>
                    <div class="card-body">
                        <?php if (!empty($aniversariantes)): ?>
                            <div class="row">
                                <?php foreach ($aniversariantes as $aniversariante): ?>
                                <div class="col-md-3 mb-2">
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar me-2">
                                            <?php echo strtoupper(substr($aniversariante['nome'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($aniversariante['nome']); ?></strong><br>
                                            <small class="text-muted">Dia <?php echo $aniversariante['dia_aniversario']; ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted">Nenhum aniversariante este mês</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Visualizar Usuário -->
    <div class="modal fade" id="modalVisualizarUsuario" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user"></i> Detalhes do Usuário</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalUsuarioContent">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin fa-2x"></i> Carregando...
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        // Gráfico de alunos por turma
        const ctx1 = document.getElementById('turmasChart').getContext('2d');
        const turmasLabels = <?php echo json_encode(array_column($alunos_por_turma, 'turma_nome')); ?>;
        const turmasValues = <?php echo json_encode(array_column($alunos_por_turma, 'total')); ?>;
        
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: turmasLabels,
                datasets: [{
                    label: 'Alunos',
                    data: turmasValues,
                    backgroundColor: '#006B3E'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
        
        // Gráfico de aprovação
        const ctx2 = document.getElementById('aprovacaoChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Reprovados'],
                datasets: [{
                    data: [<?php echo $stats['taxa_aprovacao']; ?>, <?php echo 100 - $stats['taxa_aprovacao']; ?>],
                    backgroundColor: ['#28a745', '#dc3545']
                }]
            },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        // ============================================
        // TEMPO REAL - USUÁRIOS ATIVOS
        // ============================================
        
        function carregarUsuariosAtivos() {
            $.ajax({
                url: 'index.php',
                method: 'GET',
                data: { api: 'usuarios_ativos' },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        $('#totalAtivos').text(data.total);
                        $('#ultimaAtualizacao').text(data.timestamp);
                        
                        let html = '';
                        if (data.total > 0) {
                            data.usuarios.forEach(function(usuario) {
                                let statusClass = usuario.usuario_status == 'ativo' ? 'status-ativo' : 'status-bloqueado';
                                let onlineClass = usuario.minutos_inativo < 1 ? 'status-online' : 'status-offline';
                                
                                html += `
                                    <div class="user-item" onclick="visualizarUsuario(${usuario.usuario_id})">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar me-3">
                                                    ${usuario.nome.charAt(0).toUpperCase()}
                                                </div>
                                                <div>
                                                    <strong>${usuario.nome}</strong><br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-envelope"></i> ${usuario.email} |
                                                        <i class="fas fa-tag"></i> ${usuario.tipo}
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="status-badge ${onlineClass}">
                                                    <span class="online-indicator"></span>
                                                    ${usuario.minutos_inativo < 1 ? 'Online' : `${usuario.minutos_inativo} min atrás`}
                                                </span>
                                                <br>
                                                <span class="status-badge ${statusClass} mt-1">
                                                    ${usuario.usuario_status == 'ativo' ? 'Ativo' : 'Bloqueado'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            html = '<div class="text-center text-muted p-3"><i class="fas fa-user-slash"></i> Nenhum usuário ativo no momento</div>';
                        }
                        $('#usuariosAtivosList').html(html);
                    }
                },
                error: function() {
                    $('#usuariosAtivosList').html('<div class="text-center text-danger p-3"><i class="fas fa-exclamation-triangle"></i> Erro ao carregar dados</div>');
                }
            });
        }
        
        function visualizarUsuario(usuarioId) {
            $.ajax({
                url: 'index.php',
                method: 'GET',
                data: { api: 'estatisticas_usuario', usuario_id: usuarioId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        let usuario = data.usuario;
                        let logs = data.logs;
                        
                        let logsHtml = '';
                        if (logs.length > 0) {
                            logs.forEach(function(log) {
                                logsHtml += `
                                    <div class="border-bottom pb-2 mb-2">
                                        <small class="text-muted">${log.created_at}</small>
                                        <p class="mb-0">${log.descricao || log.acao}</p>
                                    </div>
                                `;
                            });
                        } else {
                            logsHtml = '<p class="text-muted">Nenhum registro encontrado</p>';
                        }
                        
                        let html = `
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    <div class="user-avatar mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2em;">
                                        ${usuario.nome.charAt(0).toUpperCase()}
                                    </div>
                                    <h5>${usuario.nome}</h5>
                                    <p class="text-muted">${usuario.email}</p>
                                    <span class="status-badge ${usuario.status == 'ativo' ? 'status-ativo' : 'status-bloqueado'}">
                                        ${usuario.status == 'ativo' ? 'Ativo' : 'Bloqueado'}
                                    </span>
                                </div>
                                <div class="col-md-8">
                                    <h6><i class="fas fa-chart-line"></i> Estatísticas</h6>
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <div class="bg-light p-2 rounded text-center">
                                                <strong>Sessões hoje</strong><br>
                                                ${usuario.sessoes_hoje || 0}
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="bg-light p-2 rounded text-center">
                                                <strong>Último acesso</strong><br>
                                                ${usuario.ultimo_acesso ? new Date(usuario.ultimo_acesso).toLocaleString() : 'Nunca'}
                                            </div>
                                        </div>
                                    </div>
                                    <h6><i class="fas fa-history"></i> Últimas Atividades</h6>
                                    <div style="max-height: 200px; overflow-y: auto;">
                                        ${logsHtml}
                                    </div>
                                </div>
                            </div>
                        `;
                        $('#modalUsuarioContent').html(html);
                        $('#modalVisualizarUsuario').modal('show');
                    }
                }
            });
        }
        
        // Atualizar a cada 10 segundos
        carregarUsuariosAtivos();
        setInterval(carregarUsuariosAtivos, 10000);
        
        // Manter submenu aberto baseado na página atual
        const currentPage = window.location.pathname;
        if (currentPage.includes('secretaria')) { $('#menuSecretaria').addClass('open'); $('#submenuSecretaria').addClass('show'); }
        if (currentPage.includes('alunos') || currentPage.includes('professores') || currentPage.includes('turmas') || currentPage.includes('disciplinas') || currentPage.includes('notas') || currentPage.includes('chamada')) {
            $('#menuAcademico').addClass('open'); $('#submenuAcademico').addClass('show');
        }
        if (currentPage.includes('servicos_pedagogicos')) { $('#menuServicosPedagogicos').addClass('open'); $('#submenuServicosPedagogicos').addClass('show'); }
        if (currentPage.includes('biblioteca')) { $('#menuBiblioteca').addClass('open'); $('#submenuBiblioteca').addClass('show'); }
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatoriosEscola').addClass('show'); }
        if (currentPage.includes('financeiro')) { $('#menuFinanceiro').addClass('open'); $('#submenuFinanceiro').addClass('show'); }
        if (currentPage.includes('config')) { $('#menuConfiguracoes').addClass('open'); $('#submenuConfiguracoes').addClass('show'); }
    </script>
</body>
</html>