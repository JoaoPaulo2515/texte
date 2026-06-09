<?php
// escola/secretaria/rematricula.php - Gestão de Rematrícula
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$ano_letivo_atual = date('Y') . '/' . (date('Y') + 1);
$ano_letivo_anterior = (date('Y') - 1) . '/' . date('Y');

// Processar rematrícula
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'rematricular') {
    $aluno_id = $_POST['aluno_id'];
    $nova_turma_id = $_POST['nova_turma_id'];
    $novo_ano_letivo = $ano_letivo_atual;
    
    // Verificar se já está matriculado no ano atual
    $check = $conn->prepare("SELECT id FROM matriculas WHERE estudante_id = :aluno_id AND ano_letivo = :ano_letivo");
    $check->execute([':aluno_id' => $aluno_id, ':ano_letivo' => $novo_ano_letivo]);
    if ($check->rowCount() > 0) {
        $_SESSION['erro'] = "Aluno já possui matrícula para o ano letivo $novo_ano_letivo";
        header('Location: rematricula.php');
        exit;
    }
    
    // Criar nova matrícula
    $numero_processo = 'PROC-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("
        INSERT INTO matriculas (estudante_id, turma_id, ano_letivo, data_matricula, status, numero_processo)
        VALUES (:estudante_id, :turma_id, :ano_letivo, NOW(), 'ativa', :numero_processo)
    ");
    $stmt->execute([
        ':estudante_id' => $aluno_id,
        ':turma_id' => $nova_turma_id,
        ':ano_letivo' => $novo_ano_letivo,
        ':numero_processo' => $numero_processo
    ]);
    
    // Atualizar status da matrícula anterior para 'concluida'
    $stmt_old = $conn->prepare("
        UPDATE matriculas SET status = 'concluida' 
        WHERE estudante_id = :aluno_id AND ano_letivo = :ano_anterior AND status = 'ativa'
    ");
    $stmt_old->execute([':aluno_id' => $aluno_id, ':ano_anterior' => $ano_letivo_anterior]);
    
    $_SESSION['mensagem'] = "Rematrícula realizada com sucesso! Nº Processo: $numero_processo";
    header('Location: rematricula.php');
    exit;
}

// Buscar alunos aptos para rematrícula (concluíram o ano anterior)
$search = $_GET['search'] ?? '';
$turma_id = $_GET['turma'] ?? '';

$query = "
    SELECT e.*, u.nome, u.email,
           m_antiga.id as matricula_antiga_id, m_antiga.turma_id as turma_antiga_id,
           t_antiga.nome as turma_antiga_nome,
           m_antiga.numero_processo as processo_antigo
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    INNER JOIN matriculas m_antiga ON m_antiga.estudante_id = e.id
    INNER JOIN turmas t_antiga ON t_antiga.id = m_antiga.turma_id
    WHERE e.escola_id = :escola_id 
        AND m_antiga.ano_letivo = :ano_anterior 
        AND m_antiga.status = 'concluida'
        AND NOT EXISTS (
            SELECT 1 FROM matriculas m_nova 
            WHERE m_nova.estudante_id = e.id AND m_nova.ano_letivo = :ano_atual
        )
";

$params = [
    ':escola_id' => $escola_id,
    ':ano_anterior' => $ano_letivo_anterior,
    ':ano_atual' => $ano_letivo_atual
];

if ($search) {
    $query .= " AND (u.nome LIKE :search OR e.matricula LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($turma_id) {
    $query .= " AND t_antiga.id = :turma_id";
    $params[':turma_id'] = $turma_id;
}

$query .= " ORDER BY u.nome ASC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas disponíveis para o novo ano letivo
$turmas = $conn->prepare("SELECT id, nome FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas anteriores para filtro - CORRIGIDO: removido m.escola_id
$turmas_anteriores = $conn->prepare("
    SELECT DISTINCT t.id, t.nome 
    FROM turmas t
    INNER JOIN matriculas m ON m.turma_id = t.id
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.ano_letivo = :ano_anterior AND e.escola_id = :escola_id
    ORDER BY t.nome
");
$turmas_anteriores->execute([':ano_anterior' => $ano_letivo_anterior, ':escola_id' => $escola_id]);
$turmas_anteriores = $turmas_anteriores->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rematrícula | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
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
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .info-banner { background: #e3f2fd; border-left: 4px solid #006B3E; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
    </style>
</head>
<body>
  
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-sync-alt"></i> Rematrícula</h2>
            <div>
                <span class="badge bg-success">Ano Anterior: <?php echo $ano_letivo_anterior; ?></span>
                <span class="badge bg-primary ms-2">Novo Ano: <?php echo $ano_letivo_atual; ?></span>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($mensagem); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($erro); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="info-banner">
            <i class="fas fa-info-circle"></i> 
            <strong>Rematrícula Automática:</strong> Alunos que concluíram o ano letivo anterior têm direito a vaga garantida. 
            Selecione a nova turma para o aluno no ano letivo <?php echo $ano_letivo_atual; ?>.
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <input type="text" name="search" class="form-control" placeholder="Buscar por nome ou matrícula" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="turma" class="form-control">
                            <option value="">Turma do ano anterior</option>
                            <?php foreach ($turmas_anteriores as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Alunos Aptos -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-users"></i> Alunos Aptos à Rematrícula
                <span class="badge bg-secondary ms-2"><?php echo count($alunos); ?> alunos</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Matrícula</th>
                                <th>Nome</th>
                                <th>Turma Anterior</th>
                                <th>Nº Processo</th>
                                <th>Nova Turma</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $aluno): ?>
                            <form method="POST" class="rematricula-form" style="display: inline;">
                                <input type="hidden" name="action" value="rematricular">
                                <input type="hidden" name="aluno_id" value="<?php echo $aluno['id']; ?>">
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($aluno['matricula']); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($aluno['email']); ?></small>
                                     </div>
                                    </div>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($aluno['turma_antiga_nome']); ?></span></td>
                                    <td><?php echo htmlspecialchars($aluno['processo_antigo']); ?></div>
                                    <td>
                                        <select name="nova_turma_id" class="form-control form-control-sm" required>
                                            <option value="">Selecione...</option>
                                            <?php 
                                            // Sugerir próxima classe
                                            $proxima_classe = '';
                                            if (isset($aluno['classe']) && $aluno['classe']) {
                                                $num = (int)filter_var($aluno['classe'], FILTER_SANITIZE_NUMBER_INT);
                                                if ($num > 0 && $num < 13) {
                                                    $proxima_classe = ($num + 1) . 'ª Classe';
                                                }
                                            }
                                            ?>
                                            <?php foreach ($turmas as $t): ?>
                                                <option value="<?php echo $t['id']; ?>" <?php echo $t['nome'] == $proxima_classe ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($t['nome']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                     </div>
                                    </div>
                                    <td>
                                        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Confirmar rematrícula para o ano letivo <?php echo $ano_letivo_atual; ?>?')">
                                            <i class="fas fa-sync-alt"></i> Rematricular
                                        </button>
                                     </div>
                                    </div>
                                </tr>
                            </form>
                            <?php endforeach; ?>
                            <?php if (empty($alunos)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                    Nenhum aluno apto para rematrícula no momento
                                 </div>
                                </div>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
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
    </script>
</body>
</html>