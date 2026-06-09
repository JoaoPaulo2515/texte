<?php
// escola/servicos_pedagogicos/coordenacao/index.php - Coordenação Pedagógica (Continuação)
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// VERIFICAR E CRIAR TABELAS (CONTINUAÇÃO)
// ============================================

// Tabela de avaliações institucionais
$check = $conn->query("SHOW TABLES LIKE 'avaliacoes_institucionais'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE avaliacoes_institucionais (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            tipo ENUM('autoavaliacao', 'externa', 'pedagogica', 'administrativa') DEFAULT 'pedagogica',
            data_inicio DATE,
            data_fim DATE,
            status ENUM('pendente', 'em_andamento', 'concluida', 'cancelada') DEFAULT 'pendente',
            resultados TEXT,
            recomendacoes TEXT,
            responsavel VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Tabela de horários
$check = $conn->query("SHOW TABLES LIKE 'horarios_coordenacao'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE horarios_coordenacao (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            turma_id INT NOT NULL,
            disciplina_id INT NOT NULL,
            professor_id INT,
            dia_semana ENUM('segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado') NOT NULL,
            hora_inicio TIME NOT NULL,
            hora_fim TIME NOT NULL,
            sala VARCHAR(50),
            periodo VARCHAR(20),
            ano_letivo VARCHAR(9),
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE CASCADE,
            FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE CASCADE,
            FOREIGN KEY (professor_id) REFERENCES professores(id) ON DELETE SET NULL
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Adicionar comunicado
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_comunicado') {
    $titulo = $_POST['titulo'];
    $conteudo = $_POST['conteudo'];
    $tipo = $_POST['tipo'];
    $prioridade = $_POST['prioridade'];
    $destinatarios = $_POST['destinatarios'];
    $data_publicacao = $_POST['data_publicacao'];
    $data_expiracao = $_POST['data_expiracao'] ?: null;
    
    $stmt = $conn->prepare("
        INSERT INTO comunicados_coordenacao 
        (escola_id, titulo, conteudo, tipo, prioridade, destinatarios, data_publicacao, data_expiracao, status, usuario_id)
        VALUES (:escola_id, :titulo, :conteudo, :tipo, :prioridade, :destinatarios, :data_publicacao, :data_expiracao, 'ativo', :usuario_id)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':conteudo' => $conteudo,
        ':tipo' => $tipo,
        ':prioridade' => $prioridade,
        ':destinatarios' => $destinatarios,
        ':data_publicacao' => $data_publicacao,
        ':data_expiracao' => $data_expiracao,
        ':usuario_id' => $usuario_id
    ]);
    
    $_SESSION['mensagem'] = "Comunicado publicado com sucesso!";
    header("Location: index.php");
    exit;
}

// Adicionar reunião
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_reuniao') {
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $data_reuniao = $_POST['data_reuniao'];
    $duracao = $_POST['duracao'];
    $local = $_POST['local'];
    $participantes = $_POST['participantes'];
    $pauta = $_POST['pauta'];
    
    $stmt = $conn->prepare("
        INSERT INTO reunioes_coordenacao 
        (escola_id, titulo, descricao, data_reuniao, duracao, local, participantes, pauta, status, usuario_id)
        VALUES (:escola_id, :titulo, :descricao, :data_reuniao, :duracao, :local, :participantes, :pauta, 'agendada', :usuario_id)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':data_reuniao' => $data_reuniao,
        ':duracao' => $duracao,
        ':local' => $local,
        ':participantes' => $participantes,
        ':pauta' => $pauta,
        ':usuario_id' => $usuario_id
    ]);
    
    $_SESSION['mensagem'] = "Reunião agendada com sucesso!";
    header("Location: index.php");
    exit;
}

// Adicionar avaliação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_avaliacao') {
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $tipo = $_POST['tipo'];
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    $responsavel = $_POST['responsavel'];
    
    $stmt = $conn->prepare("
        INSERT INTO avaliacoes_institucionais 
        (escola_id, titulo, descricao, tipo, data_inicio, data_fim, status, responsavel)
        VALUES (:escola_id, :titulo, :descricao, :tipo, :data_inicio, :data_fim, 'pendente', :responsavel)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':tipo' => $tipo,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim,
        ':responsavel' => $responsavel
    ]);
    
    $_SESSION['mensagem'] = "Avaliação registada com sucesso!";
    header("Location: index.php");
    exit;
}

// Adicionar horário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_horario') {
    $turma_id = $_POST['turma_id'];
    $disciplina_id = $_POST['disciplina_id'];
    $professor_id = $_POST['professor_id'] ?: null;
    $dia_semana = $_POST['dia_semana'];
    $hora_inicio = $_POST['hora_inicio'];
    $hora_fim = $_POST['hora_fim'];
    $sala = $_POST['sala'];
    $periodo = $_POST['periodo'];
    $ano_letivo = $_POST['ano_letivo'];
    
    $stmt = $conn->prepare("
        INSERT INTO horarios_coordenacao 
        (escola_id, turma_id, disciplina_id, professor_id, dia_semana, hora_inicio, hora_fim, sala, periodo, ano_letivo, status)
        VALUES (:escola_id, :turma_id, :disciplina_id, :professor_id, :dia_semana, :hora_inicio, :hora_fim, :sala, :periodo, :ano_letivo, 'ativo')
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':professor_id' => $professor_id,
        ':dia_semana' => $dia_semana,
        ':hora_inicio' => $hora_inicio,
        ':hora_fim' => $hora_fim,
        ':sala' => $sala,
        ':periodo' => $periodo,
        ':ano_letivo' => $ano_letivo
    ]);
    
    $_SESSION['mensagem'] = "Horário adicionado com sucesso!";
    header("Location: index.php");
    exit;
}

// Atualizar status de reunião
if (isset($_GET['reuniao_status']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'];
    
    $stmt = $conn->prepare("UPDATE reunioes_coordenacao SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status da reunião atualizado!";
    header("Location: index.php");
    exit;
}

// Excluir comunicado
if (isset($_GET['delete_comunicado']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM comunicados_coordenacao WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Comunicado removido!";
    header("Location: index.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar comunicados ativos
$comunicados = $conn->prepare("
    SELECT * FROM comunicados_coordenacao 
    WHERE escola_id = :escola_id AND status = 'ativo' 
    ORDER BY prioridade DESC, created_at DESC 
    LIMIT 10
");
$comunicados->execute([':escola_id' => $escola_id]);
$comunicados = $comunicados->fetchAll(PDO::FETCH_ASSOC);

// Buscar próximas reuniões
$reunioes = $conn->prepare("
    SELECT * FROM reunioes_coordenacao 
    WHERE escola_id = :escola_id AND status = 'agendada' AND data_reuniao >= NOW()
    ORDER BY data_reuniao ASC 
    LIMIT 10
");
$reunioes->execute([':escola_id' => $escola_id]);
$reunioes = $reunioes->fetchAll(PDO::FETCH_ASSOC);

// Buscar avaliações em andamento
$avaliacoes = $conn->prepare("
    SELECT * FROM avaliacoes_institucionais 
    WHERE escola_id = :escola_id AND status IN ('pendente', 'em_andamento')
    ORDER BY data_inicio ASC 
    LIMIT 5
");
$avaliacoes->execute([':escola_id' => $escola_id]);
$avaliacoes = $avaliacoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas para selects
$turmas = $conn->prepare("SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas para selects
$disciplinas = $conn->prepare("SELECT id, nome, codigo FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar professores para selects
$professores = $conn->prepare("
    SELECT p.id, u.nome 
    FROM professores p
    JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.escola_id = :escola_id AND u.status = 'ativo'
    ORDER BY u.nome
");
$professores->execute([':escola_id' => $escola_id]);
$professores = $professores->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordenação Pedagógica | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        
        .comunicado-item, .reuniao-item, .avaliacao-item {
            border-left: 3px solid #006B3E;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .comunicado-urgente { border-left-color: #dc3545; background: #fff5f5; }
        .comunicado-alta { border-left-color: #fd7e14; }
        .comunicado-media { border-left-color: #ffc107; }
        .reuniao-item { border-left-color: #17a2b8; }
        .avaliacao-item { border-left-color: #28a745; }
        
        .badge-urgente { background: #dc3545; color: white; }
        .badge-alta { background: #fd7e14; color: white; }
        .badge-media { background: #ffc107; color: #000; }
        .badge-baixa { background: #28a745; color: white; }
        
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuPedagogico">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-chalkboard"></i> <span>Serviços Pedagógicos</span>
                </a>
                <ul class="nav-submenu show" id="submenuPedagogico">
                    <li class="nav-item"><a href="../gerais/index.php" class="nav-link"><i class="fas fa-cog"></i> Gerais</a></li>
                    <li class="nav-item"><a href="../disciplina_turma/index.php" class="nav-link"><i class="fas fa-link"></i> Disciplina e Turma</a></li>
                    <li class="nav-item"><a href="../disciplina_classe/index.php" class="nav-link"><i class="fas fa-layer-group"></i> Disciplina e Classe</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-users"></i> Coordenação</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-users"></i> Coordenação Pedagógica</h2>
            <div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalComunicado">
                    <i class="fas fa-bullhorn"></i> Novo Comunicado
                </button>
                <button class="btn btn-success btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#modalReuniao">
                    <i class="fas fa-calendar-plus"></i> Agendar Reunião
                </button>
                <button class="btn btn-info btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#modalAvaliacao">
                    <i class="fas fa-chart-line"></i> Nova Avaliação
                </button>
                <button class="btn btn-warning btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#modalHorario">
                    <i class="fas fa-clock"></i> Horário
                </button>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <?php
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM comunicados_coordenacao WHERE escola_id = :escola_id AND status = 'ativo'");
        $stmt->execute([':escola_id' => $escola_id]);
        $total_comunicados = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM reunioes_coordenacao WHERE escola_id = :escola_id AND status = 'agendada'");
        $stmt->execute([':escola_id' => $escola_id]);
        $total_reunioes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM avaliacoes_institucionais WHERE escola_id = :escola_id AND status IN ('pendente', 'em_andamento')");
        $stmt->execute([':escola_id' => $escola_id]);
        $total_avaliacoes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        ?>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $total_comunicados; ?></div><div>Comunicados Ativos</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $total_reunioes; ?></div><div>Reuniões Agendadas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $total_avaliacoes; ?></div><div>Avaliações em Andamento</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo count($professores); ?></div><div>Professores</div></div>
        </div>
        
        <div class="row">
            <!-- Comunicados -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-bullhorn"></i> Últimos Comunicados</div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($comunicados)): ?>
                            <p class="text-center text-muted">Nenhum comunicado publicado</p>
                        <?php else: ?>
                            <?php foreach ($comunicados as $com): ?>
                            <div class="comunicado-item comunicado-<?php echo $com['prioridade']; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="badge badge-<?php echo $com['prioridade']; ?> mb-1"><?php echo ucfirst($com['prioridade']); ?></span>
                                        <strong><?php echo htmlspecialchars($com['titulo']); ?></strong>
                                        <p class="mb-0 small"><?php echo htmlspecialchars(substr($com['conteudo'], 0, 100)); ?>...</p>
                                        <small class="text-muted"><i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($com['created_at'])); ?></small>
                                    </div>
                                    <a href="?delete_comunicado=1&id=<?php echo $com['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir este comunicado?')"><i class="fas fa-trash"></i></a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Próximas Reuniões -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-calendar-alt"></i> Próximas Reuniões</div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($reunioes)): ?>
                            <p class="text-center text-muted">Nenhuma reunião agendada</p>
                        <?php else: ?>
                            <?php foreach ($reunioes as $reu): ?>
                            <div class="reuniao-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?php echo htmlspecialchars($reu['titulo']); ?></strong>
                                        <p class="mb-0 small"><?php echo htmlspecialchars($reu['descricao']); ?></p>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($reu['data_reuniao'])); ?> |
                                            <i class="fas fa-map-marker-alt"></i> <?php echo $reu['local']; ?> |
                                            <i class="fas fa-clock"></i> <?php echo $reu['duracao']; ?> min
                                        </small>
                                    </div>
                                    <div>
                                        <a href="?reuniao_status=1&id=<?php echo $reu['id']; ?>&status=realizada" class="btn btn-sm btn-success"><i class="fas fa-check"></i></a>
                                        <a href="?reuniao_status=1&id=<?php echo $reu['id']; ?>&status=cancelada" class="btn btn-sm btn-danger"><i class="fas fa-times"></i></a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-3">
            <!-- Avaliações Institucionais -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Avaliações Institucionais</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr><th>Título</th><th>Tipo</th><th>Período</th><th>Responsável</th><th>Status</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($avaliacoes as $av): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($av['titulo']); ?></strong></div>
                                        <td><?php echo ucfirst($av['tipo']); ?></div>
                                        <td><?php echo date('d/m/Y', strtotime($av['data_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($av['data_fim'])); ?></div>
                                        <td><?php echo $av['responsavel']; ?></div>
                                        <td><span class="badge bg-warning"><?php echo str_replace('_', ' ', $av['status']); ?></span></div>
                                        <td>
                                            <button class="btn btn-sm btn-info" onclick="verAvaliacao(<?php echo $av['id']; ?>)"><i class="fas fa-eye"></i></button>
                                            <button class="btn btn-sm btn-primary" onclick="editarAvaliacao(<?php echo $av['id']; ?>)"><i class="fas fa-edit"></i></button>
                                         </div>
                                     </div>
                                    <?php endforeach; ?>
                                </tbody>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Comunicado -->
    <div class="modal fade" id="modalComunicado" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-bullhorn"></i> Novo Comunicado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_comunicado">
                    <div class="modal-body">
                        <div class="mb-3"><label>Título</label><input type="text" name="titulo" class="form-control" required></div>
                        <div class="mb-3"><label>Conteúdo</label><textarea name="conteudo" class="form-control" rows="4" required></textarea></div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Tipo</label><select name="tipo" class="form-control"><option value="informativo">Informativo</option><option value="aviso">Aviso</option><option value="urgente">Urgente</option><option value="circular">Circular</option></select></div>
                            <div class="col-md-6 mb-3"><label>Prioridade</label><select name="prioridade" class="form-control"><option value="baixa">Baixa</option><option value="media">Média</option><option value="alta">Alta</option></select></div>
                        </div>
                        <div class="mb-3"><label>Destinatários</label><input type="text" name="destinatarios" class="form-control" placeholder="Ex: Todos os professores, Turmas do 1º ciclo, etc."></div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Data de Publicação</label><input type="date" name="data_publicacao" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                            <div class="col-md-6 mb-3"><label>Data de Expiração</label><input type="date" name="data_expiracao" class="form-control"></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Publicar</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Reunião -->
    <div class="modal fade" id="modalReuniao" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus"></i> Agendar Reunião</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_reuniao">
                    <div class="modal-body">
                        <div class="mb-3"><label>Título</label><input type="text" name="titulo" class="form-control" required></div>
                        <div class="mb-3"><label>Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Data e Hora</label><input type="datetime-local" name="data_reuniao" class="form-control" required></div>
                            <div class="col-md-6 mb-3"><label>Duração (minutos)</label><input type="number" name="duracao" class="form-control" value="60" required></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Local</label><input type="text" name="local" class="form-control" placeholder="Sala de reuniões, Auditório, etc."></div>
                            <div class="col-md-6 mb-3"><label>Participantes</label><input type="text" name="participantes" class="form-control" placeholder="Ex: Coordenadores, Professores, Direção"></div>
                        </div>
                        <div class="mb-3"><label>Pauta</label><textarea name="pauta" class="form-control" rows="3" placeholder="1. Abertura\n2. Assuntos em discussão\n3. Encaminhamentos"></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Agendar</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Avaliação -->
    <div class="modal fade" id="modalAvaliacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-chart-line"></i> Nova Avaliação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_avaliacao">
                    <div class="modal-body">
                        <div class="mb-3"><label>Título</label><input type="text" name="titulo" class="form-control" required></div>
                        <div class="mb-3"><label>Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div>
                        <div class="mb-3"><label>Tipo</label><select name="tipo" class="form-control"><option value="autoavaliacao">Autoavaliação</option><option value="externa">Avaliação Externa</option><option value="pedagogica">Avaliação Pedagógica</option><option value="administrativa">Avaliação Administrativa</option></select></div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Data Início</label><input type="date" name="data_inicio" class="form-control" required></div>
                            <div class="col-md-6 mb-3"><label>Data Fim</label><input type="date" name="data_fim" class="form-control" required></div>
                        </div>
                        <div class="mb-3"><label>Responsável</label><input type="text" name="responsavel" class="form-control" placeholder="Nome do responsável pela avaliação"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-info">Registrar</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Horário -->
    <div class="modal fade" id="modalHorario" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-clock"></i> Adicionar Horário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_horario">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Turma</label><select name="turma_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($turmas as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6 mb-3"><label>Disciplina</label><select name="disciplina_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($disciplinas as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option><?php endforeach; ?></select></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Professor</label><select name="professor_id" class="form-control"><option value="">Selecione...</option><?php foreach ($professores as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6 mb-3"><label>Dia da Semana</label><select name="dia_semana" class="form-control" required><option value="segunda">Segunda-feira</option><option value="terca">Terça-feira</option><option value="quarta">Quarta-feira</option><option value="quinta">Quinta-feira</option><option value="sexta">Sexta-feira</option><option value="sabado">Sábado</option></select></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Hora Início</label><input type="time" name="hora_inicio" class="form-control" required></div>
                            <div class="col-md-6 mb-3"><label>Hora Fim</label><input type="time" name="hora_fim" class="form-control" required></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Sala</label><input type="text" name="sala" class="form-control" placeholder="Ex: Sala 101"></div>
                            <div class="col-md-6 mb-3"><label>Período</label><input type="text" name="periodo" class="form-control" placeholder="Ex: 1º Bimestre"></div>
                        </div>
                        <div class="mb-3"><label>Ano Letivo</label><input type="text" name="ano_letivo" class="form-control" value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" required></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning">Salvar Horário</button></div>
                </form>
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
        
        function verAvaliacao(id) {
            alert('Visualizar avaliação ID: ' + id + ' - Funcionalidade em desenvolvimento');
        }
        
        function editarAvaliacao(id) {
            alert('Editar avaliação ID: ' + id + ' - Funcionalidade em desenvolvimento');
        }
    </script>
</body>
</html>