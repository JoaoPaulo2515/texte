<?php
// escola/config/sistema/index.php - Abrir Sistema (Calendário de Provas e Lançamento de Notas)
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
// VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================

// Verificar se a tabela escola_parametros_sistema existe e tem a estrutura correta
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
}

// Verificar se a tabela calendario_provas existe
$check = $conn->query("SHOW TABLES LIKE 'calendario_provas'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE calendario_provas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            disciplina_id INT,
            turma_id INT,
            data_prova DATE NOT NULL,
            hora_inicio TIME,
            hora_fim TIME,
            sala VARCHAR(50),
            observacoes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE SET NULL,
            FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE SET NULL
        )
    ");
}

// Verificar a estrutura da tabela escola_parametros_sistema e adicionar colunas se necessário
try {
    $columns = $conn->query("DESCRIBE escola_parametros_sistema");
    $existingColumns = [];
    while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $col['Field'];
    }
    
    if (!in_array('parametro', $existingColumns)) {
        $conn->exec("ALTER TABLE escola_parametros_sistema ADD COLUMN parametro VARCHAR(100) NOT NULL DEFAULT 'lancamento_notas' AFTER id");
    }
    if (!in_array('valor', $existingColumns)) {
        $conn->exec("ALTER TABLE escola_parametros_sistema ADD COLUMN valor TEXT AFTER parametro");
    }
    if (!in_array('data_abertura', $existingColumns)) {
        $conn->exec("ALTER TABLE escola_parametros_sistema ADD COLUMN data_abertura DATETIME AFTER valor");
    }
    if (!in_array('data_fechamento', $existingColumns)) {
        $conn->exec("ALTER TABLE escola_parametros_sistema ADD COLUMN data_fechamento DATETIME AFTER data_abertura");
    }
} catch (PDOException $e) {
    // Tabela pode não existir ainda
}

// Inserir parâmetro padrão se não existir
$stmt = $conn->prepare("
    INSERT IGNORE INTO escola_parametros_sistema (escola_id, parametro, valor) 
    SELECT id, 'lancamento_notas', 'fechado' FROM escolas
");
$stmt->execute();

// ============================================
// PROCESSAR AÇÕES
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'toggle_lancamento_notas') {
        $status = $_POST['status'];
        $data_abertura = $status == 'aberto' ? date('Y-m-d H:i:s') : null;
        $data_fechamento = $status == 'fechado' ? date('Y-m-d H:i:s') : null;
        
        // Verificar se o registro existe
        $check = $conn->prepare("SELECT id FROM escola_parametros_sistema WHERE escola_id = :escola_id AND parametro = 'lancamento_notas'");
        $check->execute([':escola_id' => $escola_id]);
        
        if ($check->rowCount() > 0) {
            // Atualizar existente
            $stmt = $conn->prepare("
                UPDATE escola_parametros_sistema 
                SET valor = :status, 
                    data_abertura = :data_abertura, 
                    data_fechamento = :data_fechamento,
                    atualizado_em = NOW()
                WHERE escola_id = :escola_id AND parametro = 'lancamento_notas'
            ");
        } else {
            // Inserir novo
            $stmt = $conn->prepare("
                INSERT INTO escola_parametros_sistema (escola_id, parametro, valor, data_abertura, data_fechamento, criado_em)
                VALUES (:escola_id, 'lancamento_notas', :status, :data_abertura, :data_fechamento, NOW())
            ");
        }
        
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':status' => $status,
            ':data_abertura' => $data_abertura,
            ':data_fechamento' => $data_fechamento
        ]);
        
        $_SESSION['mensagem'] = "Lançamento de notas " . ($status == 'aberto' ? 'aberto' : 'fechado') . " com sucesso!";
        header("Location: index.php");
        exit;
    }
    
    if ($acao == 'add_prova') {
        $titulo = $_POST['titulo'];
        $disciplina_id = $_POST['disciplina_id'] ?: null;
        $turma_id = $_POST['turma_id'] ?: null;
        $data_prova = $_POST['data_prova'];
        $hora_inicio = $_POST['hora_inicio'];
        $hora_fim = $_POST['hora_fim'];
        $sala = $_POST['sala'];
        $observacoes = $_POST['observacoes'];
        
        $stmt = $conn->prepare("
            INSERT INTO calendario_provas (escola_id, titulo, disciplina_id, turma_id, data_prova, hora_inicio, hora_fim, sala, observacoes, created_at)
            VALUES (:escola_id, :titulo, :disciplina_id, :turma_id, :data_prova, :hora_inicio, :hora_fim, :sala, :observacoes, NOW())
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':titulo' => $titulo,
            ':disciplina_id' => $disciplina_id,
            ':turma_id' => $turma_id,
            ':data_prova' => $data_prova,
            ':hora_inicio' => $hora_inicio,
            ':hora_fim' => $hora_fim,
            ':sala' => $sala,
            ':observacoes' => $observacoes
        ]);
        $_SESSION['mensagem'] = "Prova adicionada ao calendário!";
        header("Location: index.php");
        exit;
    }
    
    if ($acao == 'delete_prova') {
        $id = $_POST['id'];
        $stmt = $conn->prepare("DELETE FROM calendario_provas WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $_SESSION['mensagem'] = "Prova removida!";
        header("Location: index.php");
        exit;
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar status do lançamento de notas (CORRIGIDO)
try {
    $stmt = $conn->prepare("SELECT valor, data_abertura, data_fechamento FROM escola_parametros_sistema WHERE escola_id = :escola_id AND parametro = 'lancamento_notas'");
    $stmt->execute([':escola_id' => $escola_id]);
    $lancamento = $stmt->fetch(PDO::FETCH_ASSOC);
    $status_notas = $lancamento['valor'] ?? 'fechado';
    $data_abertura = $lancamento['data_abertura'] ?? null;
    $data_fechamento = $lancamento['data_fechamento'] ?? null;
} catch (PDOException $e) {
    $status_notas = 'fechado';
    $data_abertura = null;
    $data_fechamento = null;
}

// Buscar calendário de provas
try {
    $provas = $conn->prepare("
        SELECT cp.*, d.nome as disciplina_nome, t.nome as turma_nome
        FROM calendario_provas cp
        LEFT JOIN disciplinas d ON d.id = cp.disciplina_id
        LEFT JOIN turmas t ON t.id = cp.turma_id
        WHERE cp.escola_id = :escola_id AND cp.data_prova >= CURDATE()
        ORDER BY cp.data_prova ASC
    ");
    $provas->execute([':escola_id' => $escola_id]);
    $provas = $provas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $provas = [];
}

// Buscar disciplinas para o select
try {
    $disciplinas = $conn->prepare("SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
    $disciplinas->execute([':escola_id' => $escola_id]);
    $disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $disciplinas = [];
}

// Buscar turmas para o select
try {
    $turmas = $conn->prepare("SELECT id, nome FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
    $turmas->execute([':escola_id' => $escola_id]);
    $turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $turmas = [];
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Abrir Sistema | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        .btn-primary:hover { background: #004d2d; }
        .btn-success { background: #28a745; border: none; }
        .btn-danger { background: #dc3545; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .status-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .status-aberto { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .status-fechado { background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%); }
        .toggle-switch { width: 60px; height: 30px; background: #ccc; border-radius: 15px; position: relative; cursor: pointer; transition: all 0.3s; }
        .toggle-switch.active { background: #28a745; }
        .toggle-switch:after { content: ''; width: 26px; height: 26px; background: white; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: all 0.3s; }
        .toggle-switch.active:after { left: 32px; }
        .event-item { border-left: 3px solid #006B3E; margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 8px; }
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
                <small><i class="fas fa-building"></i> Secretaria</small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu" id="menuSecretaria">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-building"></i> Secretaria</a>
                <ul class="nav-submenu" id="submenuSecretaria">
                    <li class="nav-item"><a href="../lista_alunos.php" class="nav-link"><i class="fas fa-list"></i> Lista de Alunos</a></li>
                    <li class="nav-item"><a href="../alunos_matriculados.php" class="nav-link"><i class="fas fa-check-circle"></i> Alunos Matriculados</a></li>
                    <li class="nav-item"><a href="../inscricoes.php" class="nav-link"><i class="fas fa-file-signature"></i> Inscrições</a></li>
                    <li class="nav-item"><a href="../rematricula.php" class="nav-link"><i class="fas fa-sync-alt"></i> Rematrícula</a></li>
                    <li class="nav-item"><a href="../matricula.php" class="nav-link"><i class="fas fa-user-plus"></i> Matrícula</a></li>
                </ul>
            </li>
            <li class="nav-item has-submenu open" id="menuConfiguracoes">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-cogs"></i> <span>Configurações</span>
                </a>
                <ul class="nav-submenu show" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="../banco/index.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="../pagamento/index.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chalkboard"></i> Abrir Sistema</h2>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($mensagem); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Card de Status do Lançamento de Notas -->
        <div class="status-card <?php echo $status_notas == 'aberto' ? 'status-aberto' : 'status-fechado'; ?>">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3><i class="fas fa-<?php echo $status_notas == 'aberto' ? 'lock-open' : 'lock'; ?>"></i> Lançamento de Notas</h3>
                    <p class="mb-0">Status: <strong><?php echo strtoupper($status_notas); ?></strong></p>
                    <?php if ($data_abertura): ?>
                        <small>Aberto em: <?php echo date('d/m/Y H:i', strtotime($data_abertura)); ?></small>
                    <?php endif; ?>
                    <?php if ($data_fechamento): ?>
                        <br><small>Fechado em: <?php echo date('d/m/Y H:i', strtotime($data_fechamento)); ?></small>
                    <?php endif; ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="toggle_lancamento_notas">
                    <input type="hidden" name="status" value="<?php echo $status_notas == 'aberto' ? 'fechado' : 'aberto'; ?>">
                    <button type="submit" class="btn btn-light btn-lg">
                        <i class="fas fa-<?php echo $status_notas == 'aberto' ? 'lock' : 'lock-open'; ?>"></i>
                        <?php echo $status_notas == 'aberto' ? 'Fechar Lançamento' : 'Abrir Lançamento'; ?>
                    </button>
                </form>
            </div>
        </div>
        
        <div class="row">
            <!-- Calendário de Provas -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-calendar-alt"></i> Calendário de Provas</span>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaProva">
                            <i class="fas fa-plus"></i> Adicionar Prova
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($provas)): ?>
                            <p class="text-center text-muted">Nenhuma prova agendada</p>
                        <?php else: ?>
                            <?php foreach ($provas as $prova): ?>
                            <div class="event-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?php echo date('d/m/Y', strtotime($prova['data_prova'])); ?></strong>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($prova['titulo']); ?></h6>
                                        <small class="text-muted">
                                            <i class="fas fa-book"></i> <?php echo $prova['disciplina_nome'] ?? 'N/A'; ?> |
                                            <i class="fas fa-users"></i> <?php echo $prova['turma_nome'] ?? 'N/A'; ?> |
                                            <i class="fas fa-clock"></i> <?php echo substr($prova['hora_inicio'], 0, 5); ?> - <?php echo substr($prova['hora_fim'], 0, 5); ?> |
                                            <i class="fas fa-door-open"></i> Sala: <?php echo $prova['sala'] ?? 'N/A'; ?>
                                        </small>
                                        <?php if ($prova['observacoes']): ?>
                                            <br><small class="text-muted"><i class="fas fa-comment"></i> <?php echo htmlspecialchars($prova['observacoes']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Remover esta prova?')">
                                        <input type="hidden" name="acao" value="delete_prova">
                                        <input type="hidden" name="id" value="<?php echo $prova['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Informações do Sistema -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-info-circle"></i> Informações do Sistema
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li class="mb-3">
                                <i class="fas fa-calendar-check text-success"></i> 
                                <strong>Período Letivo Atual:</strong> 
                                <?php
                                try {
                                    $stmt = $conn->prepare("SELECT ano FROM anos_letivos WHERE escola_id = :escola_id AND status = 'ativo'");
                                    $stmt->execute([':escola_id' => $escola_id]);
                                    $ano = $stmt->fetch(PDO::FETCH_ASSOC);
                                    echo $ano['ano'] ?? 'Não definido';
                                } catch (PDOException $e) {
                                    echo 'Não definido';
                                }
                                ?>
                            </li>
                            <li class="mb-3">
                                <i class="fas fa-chart-line text-primary"></i> 
                                <strong>Período de Avaliação:</strong> 
                                <?php echo $status_notas == 'aberto' ? 'Lançamento de notas permitido' : 'Lançamento de notas bloqueado'; ?>
                            </li>
                            <li class="mb-3">
                                <i class="fas fa-file-alt text-warning"></i> 
                                <strong>Total de Provas Agendadas:</strong> <?php echo count($provas); ?>
                            </li>
                            <li class="mb-3">
                                <i class="fas fa-clock text-info"></i> 
                                <strong>Última Atualização:</strong> <?php echo date('d/m/Y H:i'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Prova -->
    <div class="modal fade" id="modalNovaProva" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus"></i> Agendar Prova</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_prova">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Título da Prova</label>
                            <input type="text" name="titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Disciplina</label>
                            <select name="disciplina_id" class="form-control">
                                <option value="">Selecione...</option>
                                <?php foreach ($disciplinas as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Turma</label>
                            <select name="turma_id" class="form-control">
                                <option value="">Selecione...</option>
                                <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Data</label>
                                <input type="date" name="data_prova" class="form-control" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label>Hora Início</label>
                                <input type="time" name="hora_inicio" class="form-control" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label>Hora Fim</label>
                                <input type="time" name="hora_fim" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Sala</label>
                            <input type="text" name="sala" class="form-control" placeholder="Ex: Sala 101">
                        </div>
                        <div class="mb-3">
                            <label>Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Agendar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        
        // Manter submenu aberto baseado na página atual
        const currentPage = window.location.pathname;
        if (currentPage.includes('secretaria')) {
            $('#menuSecretaria').addClass('open');
            $('#submenuSecretaria').addClass('show');
        }
        if (currentPage.includes('config')) {
            $('#menuConfiguracoes').addClass('open');
            $('#submenuConfiguracoes').addClass('show');
        }
    </script>
</body>
</html>