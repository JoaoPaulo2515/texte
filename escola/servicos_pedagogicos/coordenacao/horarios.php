<?php
// escola/servicos_pedagogicos/coordenacao/horarios.php - Gestão de Horários
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
// VERIFICAR E CRIAR TABELA
// ============================================

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
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    
    // Verificar conflito de horário
    $check = $conn->prepare("
        SELECT * FROM horarios_coordenacao 
        WHERE escola_id = :escola_id 
            AND turma_id = :turma_id 
            AND dia_semana = :dia_semana 
            AND ((hora_inicio <= :hora_inicio AND hora_fim > :hora_inicio) 
                OR (hora_inicio < :hora_fim AND hora_fim >= :hora_fim)
                OR (hora_inicio >= :hora_inicio AND hora_fim <= :hora_fim))
            AND status = 'ativo'
    ");
    $check->execute([
        ':escola_id' => $escola_id,
        ':turma_id' => $turma_id,
        ':dia_semana' => $dia_semana,
        ':hora_inicio' => $hora_inicio,
        ':hora_fim' => $hora_fim
    ]);
    
    if ($check->rowCount() > 0) {
        $_SESSION['erro'] = "Conflito de horário! Já existe uma aula agendada para este horário.";
        header("Location: horarios.php");
        exit;
    }
    
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
    header("Location: horarios.php");
    exit;
}

// Editar horário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_horario') {
    $id = $_POST['id'];
    $professor_id = $_POST['professor_id'] ?: null;
    $sala = $_POST['sala'];
    $periodo = $_POST['periodo'];
    
    $stmt = $conn->prepare("
        UPDATE horarios_coordenacao 
        SET professor_id = :professor_id, sala = :sala, periodo = :periodo
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':professor_id' => $professor_id,
        ':sala' => $sala,
        ':periodo' => $periodo
    ]);
    
    $_SESSION['mensagem'] = "Horário atualizado!";
    header("Location: horarios.php");
    exit;
}

// Ativar/Desativar horário
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE horarios_coordenacao SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: horarios.php");
    exit;
}

// Excluir horário
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM horarios_coordenacao WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Horário excluído!";
    header("Location: horarios.php");
    exit;
}

// Gerar horário em PDF (simplificado)
if (isset($_GET['pdf']) && isset($_GET['turma_id'])) {
    $turma_id = $_GET['turma_id'];
    
    // Buscar dados da turma
    $stmt = $conn->prepare("SELECT * FROM turmas WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $turma_id, ':escola_id' => $escola_id]);
    $turma = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Buscar horários da turma
    $stmt = $conn->prepare("
        SELECT h.*, d.nome as disciplina_nome, d.codigo, p.nome as professor_nome
        FROM horarios_coordenacao h
        JOIN disciplinas d ON d.id = h.disciplina_id
        LEFT JOIN professores prof ON prof.id = h.professor_id
        LEFT JOIN usuarios p ON p.id = prof.usuario_id
        WHERE h.turma_id = :turma_id AND h.escola_id = :escola_id AND h.status = 'ativo'
        ORDER BY FIELD(h.dia_semana, 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'), h.hora_inicio
    ");
    $stmt->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
    $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Gerar HTML para PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Horário - ' . $turma['nome'] . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #006B3E; text-align: center; }
            .info { margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background: #006B3E; color: white; }
            .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
        </style>
    </head>
    <body>
        <h1>' . htmlspecialchars($turma['nome']) . '</h1>
        <div class="info">
            <p><strong>Classe/Ano:</strong> ' . $turma['ano'] . '</p>
            <p><strong>Turno:</strong> ' . ucfirst($turma['turno']) . '</p>
            <p><strong>Sala:</strong> ' . $turma['sala'] . '</p>
        </div>
        <table>
            <thead>
                <tr><th>Dia</th><th>Horário</th><th>Disciplina</th><th>Professor</th><th>Sala</th></tr>
            </thead>
            <tbody>';
    
    $dias = ['segunda' => 'Segunda-feira', 'terca' => 'Terça-feira', 'quarta' => 'Quarta-feira', 'quinta' => 'Quinta-feira', 'sexta' => 'Sexta-feira', 'sabado' => 'Sábado'];
    
    foreach ($horarios as $h) {
        $html .= '<tr>
            <td>' . $dias[$h['dia_semana']] . '</td>
            <td>' . substr($h['hora_inicio'], 0, 5) . ' - ' . substr($h['hora_fim'], 0, 5) . '</td>
            <td>' . htmlspecialchars($h['disciplina_nome']) . '</td>
            <td>' . ($h['professor_nome'] ?? 'Não atribuído') . '</td>
            <td>' . ($h['sala'] ?? $turma['sala']) . '</td>
        </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
        <div class="footer">
            <p>Documento gerado por SIGE Angola - Sistema Integrado de Gestão Escolar</p>
            <p>Data de emissão: ' . date('d/m/Y H:i:s') . '</p>
        </div>
    </body>
    </html>';
    
    // Para demonstração, exibir HTML (em produção usaria dompdf)
    echo $html;
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$turma_filter = $_GET['turma'] ?? '';
$dia_filter = $_GET['dia'] ?? '';

$sql = "
    SELECT h.*, 
           t.nome as turma_nome, t.ano as turma_ano,
           d.nome as disciplina_nome, d.codigo,
           p.nome as professor_nome
    FROM horarios_coordenacao h
    JOIN turmas t ON t.id = h.turma_id
    JOIN disciplinas d ON d.id = h.disciplina_id
    LEFT JOIN professores prof ON prof.id = h.professor_id
    LEFT JOIN usuarios p ON p.id = prof.usuario_id
    WHERE h.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($turma_filter) {
    $sql .= " AND h.turma_id = :turma_id";
    $params[':turma_id'] = $turma_filter;
}
if ($dia_filter) {
    $sql .= " AND h.dia_semana = :dia_semana";
    $params[':dia_semana'] = $dia_filter;
}

$sql .= " ORDER BY t.nome, FIELD(h.dia_semana, 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'), h.hora_inicio";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas para filtro
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

// Estatísticas
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM horarios_coordenacao WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(DISTINCT turma_id) as total FROM horarios_coordenacao WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['turmas_com_horario'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Horários | Coordenação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; font-size: 0.85em; }
        
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .table-responsive { overflow-x: auto; }
        
        .horario-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 10px;
            border-left: 3px solid #006B3E;
        }
        
        .horario-hora {
            font-weight: bold;
            color: #006B3E;
        }
        
        .visao-horario {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .grade-horario {
            width: 100%;
            border-collapse: collapse;
        }
        .grade-horario th, .grade-horario td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
            vertical-align: top;
        }
        .grade-horario th {
            background: #006B3E;
            color: white;
        }
        .grade-horario td {
            height: 80px;
        }
        .aula-item {
            background: #e8f5e9;
            border-radius: 5px;
            padding: 5px;
            margin-bottom: 5px;
            font-size: 0.8em;
        }
        
        .dias-semana {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .dia-btn {
            padding: 8px 15px;
            border-radius: 20px;
            text-decoration: none;
            background: #f0f2f5;
            color: #333;
        }
        .dia-btn.active {
            background: #006B3E;
            color: white;
        }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-clock"></i> Gestão de Horários</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoHorario">
                <i class="fas fa-plus"></i> Adicionar Horário
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total de Horários</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['turmas_com_horario']; ?></div><div class="stat-label">Turmas com Horário</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo count($turmas); ?></div><div class="stat-label">Turmas Ativas</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <select name="turma" class="form-control">
                            <option value="">Todas as turmas</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_filter == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select name="dia" class="form-control">
                            <option value="">Todos os dias</option>
                            <option value="segunda" <?php echo $dia_filter == 'segunda' ? 'selected' : ''; ?>>Segunda-feira</option>
                            <option value="terca" <?php echo $dia_filter == 'terca' ? 'selected' : ''; ?>>Terça-feira</option>
                            <option value="quarta" <?php echo $dia_filter == 'quarta' ? 'selected' : ''; ?>>Quarta-feira</option>
                            <option value="quinta" <?php echo $dia_filter == 'quinta' ? 'selected' : ''; ?>>Quinta-feira</option>
                            <option value="sexta" <?php echo $dia_filter == 'sexta' ? 'selected' : ''; ?>>Sexta-feira</option>
                            <option value="sabado" <?php echo $dia_filter == 'sabado' ? 'selected' : ''; ?>>Sábado</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Horários -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Horários</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaHorarios">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Turma</th>
                                <th>Disciplina</th>
                                <th>Professor</th>
                                <th>Dia</th>
                                <th>Horário</th>
                                <th>Sala</th>
                                <th>Período</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $dias = ['segunda' => 'Segunda', 'terca' => 'Terça', 'quarta' => 'Quarta', 'quinta' => 'Quinta', 'sexta' => 'Sexta', 'sabado' => 'Sábado'];
                            foreach ($horarios as $h): 
                            ?>
                            <tr>
                                <td><?php echo $h['id']; ?></td>
                                <td><?php echo $h['turma_ano']; ?> - <?php echo htmlspecialchars($h['turma_nome']); ?></td>
                                <td><?php echo htmlspecialchars($h['disciplina_nome']); ?> <small>(<?php echo $h['codigo']; ?>)</small></td>
                                <td><?php echo $h['professor_nome'] ?? '<span class="text-muted">Não atribuído</span>'; ?></td>
                                <td><?php echo $dias[$h['dia_semana']]; ?></td>
                                <td><span class="horario-hora"><?php echo substr($h['hora_inicio'], 0, 5); ?> - <?php echo substr($h['hora_fim'], 0, 5); ?></span></div>
                                <td><?php echo $h['sala'] ?? '-'; ?></td>
                                <td><?php echo $h['periodo']; ?></td>
                                <td><span class="badge <?php echo $h['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $h['status']; ?></span></div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-warning" onclick="editarHorario(<?php echo $h['id']; ?>, <?php echo $h['professor_id'] ?: 'null'; ?>, '<?php echo addslashes($h['sala']); ?>', '<?php echo $h['periodo']; ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?toggle=1&id=<?php echo $h['id']; ?>&status=<?php echo $h['status']; ?>" class="btn btn-success">
                                            <i class="fas fa-toggle-<?php echo $h['status'] == 'ativo' ? 'off' : 'on'; ?>"></i>
                                        </a>
                                        <a href="?delete=1&id=<?php echo $h['id']; ?>" class="btn btn-danger" onclick="return confirm('Excluir este horário?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                            <?php if (empty($horarios)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                    Nenhum horário encontrado
                                 </div>
                             </div>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
        
        <!-- Visualização de Horário por Turma -->
        <div class="card">
            <div class="card-header"><i class="fas fa-calendar-week"></i> Visualização de Horário</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <select id="selectTurmaHorario" class="form-control">
                            <option value="">Selecione uma turma...</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <button class="btn btn-info" onclick="carregarHorarioTurma()"><i class="fas fa-eye"></i> Visualizar</button>
                        <button class="btn btn-secondary" onclick="imprimirHorario()"><i class="fas fa-print"></i> Imprimir</button>
                        <button class="btn btn-danger" onclick="gerarPDF()"><i class="fas fa-file-pdf"></i> Gerar PDF</button>
                    </div>
                </div>
                <div id="horarioTurmaContainer" class="mt-4" style="display: none;">
                    <div class="visao-horario">
                        <h4 id="horarioTurmaTitulo"></h4>
                        <div id="horarioGrade"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Horário -->
    <div class="modal fade" id="modalNovoHorario" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Adicionar Horário</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_horario">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Turma</label>
                                <select name="turma_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($turmas as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Disciplina</label>
                                <select name="disciplina_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($disciplinas as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?> (<?php echo $d['codigo']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Professor</label>
                                <select name="professor_id" class="form-control">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($professores as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Dia da Semana</label>
                                <select name="dia_semana" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <option value="segunda">Segunda-feira</option>
                                    <option value="terca">Terça-feira</option>
                                    <option value="quarta">Quarta-feira</option>
                                    <option value="quinta">Quinta-feira</option>
                                    <option value="sexta">Sexta-feira</option>
                                    <option value="sabado">Sábado</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Hora Início</label>
                                <input type="time" name="hora_inicio" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Hora Fim</label>
                                <input type="time" name="hora_fim" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Sala</label>
                                <input type="text" name="sala" class="form-control" placeholder="Ex: Sala 101">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Período</label>
                                <input type="text" name="periodo" class="form-control" placeholder="Ex: 1º Bimestre">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Ano Letivo</label>
                            <input type="text" name="ano_letivo" class="form-control" value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" required>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> O sistema verifica automaticamente conflitos de horário.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Horário</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Horário -->
    <div class="modal fade" id="modalEditarHorario" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Horário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="edit_horario">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Professor</label>
                            <select name="professor_id" id="edit_professor_id" class="form-control">
                                <option value="">Selecione...</option>
                                <?php foreach ($professores as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Sala</label>
                            <input type="text" name="sala" id="edit_sala" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label>Período</label>
                            <input type="text" name="periodo" id="edit_periodo" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar</button>
                    </div>
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
        
        $('#tabelaHorarios').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']]
        });
        
        function editarHorario(id, professorId, sala, periodo) {
            $('#edit_id').val(id);
            $('#edit_professor_id').val(professorId);
            $('#edit_sala').val(sala);
            $('#edit_periodo').val(periodo);
            $('#modalEditarHorario').modal('show');
        }
        
        function carregarHorarioTurma() {
            var turmaId = $('#selectTurmaHorario').val();
            if (!turmaId) {
                alert('Selecione uma turma primeiro!');
                return;
            }
            
            $.ajax({
                url: 'horarios.php',
                method: 'GET',
                data: { api: 'horario_turma', turma_id: turmaId },
                success: function(data) {
                    try {
                        var response = JSON.parse(data);
                        if (response.success) {
                            $('#horarioTurmaTitulo').text('Horário - ' + response.turma_nome);
                            $('#horarioGrade').html(response.grade);
                            $('#horarioTurmaContainer').show();
                        } else {
                            alert(response.message || 'Erro ao carregar horário');
                        }
                    } catch(e) {
                        $('#horarioGrade').html(data);
                        $('#horarioTurmaContainer').show();
                    }
                },
                error: function() {
                    alert('Erro ao carregar horário');
                }
            });
        }
        
        function imprimirHorario() {
            var conteudo = document.getElementById('horarioTurmaContainer').innerHTML;
            var janela = window.open('', '_blank');
            janela.document.write('<html><head><title>Horário</title>');
            janela.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">');
            janela.document.write('<style>body { padding: 20px; } table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #ddd; padding: 8px; text-align: center; } th { background: #006B3E; color: white; }</style>');
            janela.document.write('</head><body>');
            janela.document.write(conteudo);
            janela.document.write('</body></html>');
            janela.document.close();
            janela.print();
        }
        
        function gerarPDF() {
            var turmaId = $('#selectTurmaHorario').val();
            if (turmaId) {
                window.open('horarios.php?pdf=1&turma_id=' + turmaId, '_blank');
            } else {
                alert('Selecione uma turma primeiro!');
            }
        }
    </script>
</body>
</html>