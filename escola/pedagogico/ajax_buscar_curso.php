<?php
// escola/pedagogico/cursos.php - Gestão de Cursos

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// Incluir modal de confirmação global
include_once __DIR__ . '/includes/modal_confirmacao.php';

// ============================================
// VERIFICAR E CRIAR TABELA SE NÃO EXISTIR
// ============================================
$sql_create_table = "
CREATE TABLE IF NOT EXISTS `cursos` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(20) NOT NULL,
    `nome` VARCHAR(100) NOT NULL,
    `sigla` VARCHAR(10) DEFAULT NULL,
    `descricao` TEXT,
    `nivel_id` INT,
    `duracao_meses` INT DEFAULT NULL,
    `duracao_anos` INT DEFAULT NULL,
    `carga_horaria_total` INT DEFAULT NULL,
    `valor_mensalidade` DECIMAL(12,2) DEFAULT NULL,
    `requisitos` TEXT,
    `certificado_emitido` TEXT,
    `escola_id` INT NOT NULL,
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_codigo` (`escola_id`, `codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";
try {
    $conn->exec($sql_create_table);
} catch (PDOException $e) {
    // Tabela já existe ou erro
}

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

// Inserir novo curso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $codigo = trim($_POST['codigo']);
    $nome = trim($_POST['nome']);
    $sigla = trim($_POST['sigla']);
    $descricao = trim($_POST['descricao']);
    $nivel_id = !empty($_POST['nivel_id']) ? (int)$_POST['nivel_id'] : null;
    $duracao_meses = !empty($_POST['duracao_meses']) ? (int)$_POST['duracao_meses'] : null;
    $duracao_anos = !empty($_POST['duracao_anos']) ? (int)$_POST['duracao_anos'] : null;
    $carga_horaria_total = !empty($_POST['carga_horaria_total']) ? (int)$_POST['carga_horaria_total'] : null;
    $valor_mensalidade = !empty($_POST['valor_mensalidade']) ? (float)str_replace(',', '.', $_POST['valor_mensalidade']) : null;
    $requisitos = trim($_POST['requisitos']);
    $certificado_emitido = trim($_POST['certificado_emitido']);
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Verificar se já existe curso com este código
    $sql_check = "SELECT id FROM cursos WHERE escola_id = :escola_id AND codigo = :codigo";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':codigo' => $codigo]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe um curso cadastrado com o código '$codigo'.";
    } else {
        $sql = "INSERT INTO cursos (escola_id, codigo, nome, sigla, descricao, nivel_id, duracao_meses, duracao_anos, carga_horaria_total, valor_mensalidade, requisitos, certificado_emitido, status, created_at) 
                VALUES (:escola_id, :codigo, :nome, :sigla, :descricao, :nivel_id, :duracao_meses, :duracao_anos, :carga_horaria_total, :valor_mensalidade, :requisitos, :certificado_emitido, :status, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':codigo' => $codigo,
            ':nome' => $nome,
            ':sigla' => $sigla,
            ':descricao' => $descricao,
            ':nivel_id' => $nivel_id,
            ':duracao_meses' => $duracao_meses,
            ':duracao_anos' => $duracao_anos,
            ':carga_horaria_total' => $carga_horaria_total,
            ':valor_mensalidade' => $valor_mensalidade,
            ':requisitos' => $requisitos,
            ':certificado_emitido' => $certificado_emitido,
            ':status' => $status
        ]);
        
        $mensagem = "Curso cadastrado com sucesso!";
    }
}

// Atualizar curso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $codigo = trim($_POST['codigo']);
    $nome = trim($_POST['nome']);
    $sigla = trim($_POST['sigla']);
    $descricao = trim($_POST['descricao']);
    $nivel_id = !empty($_POST['nivel_id']) ? (int)$_POST['nivel_id'] : null;
    $duracao_meses = !empty($_POST['duracao_meses']) ? (int)$_POST['duracao_meses'] : null;
    $duracao_anos = !empty($_POST['duracao_anos']) ? (int)$_POST['duracao_anos'] : null;
    $carga_horaria_total = !empty($_POST['carga_horaria_total']) ? (int)$_POST['carga_horaria_total'] : null;
    $valor_mensalidade = !empty($_POST['valor_mensalidade']) ? (float)str_replace(',', '.', $_POST['valor_mensalidade']) : null;
    $requisitos = trim($_POST['requisitos']);
    $certificado_emitido = trim($_POST['certificado_emitido']);
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Verificar se já existe outro curso com este código
    $sql_check = "SELECT id FROM cursos WHERE escola_id = :escola_id AND codigo = :codigo AND id != :id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':codigo' => $codigo, ':id' => $id]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe um curso cadastrado com o código '$codigo'.";
    } else {
        $sql = "UPDATE cursos SET 
                codigo = :codigo,
                nome = :nome,
                sigla = :sigla,
                descricao = :descricao,
                nivel_id = :nivel_id,
                duracao_meses = :duracao_meses,
                duracao_anos = :duracao_anos,
                carga_horaria_total = :carga_horaria_total,
                valor_mensalidade = :valor_mensalidade,
                requisitos = :requisitos,
                certificado_emitido = :certificado_emitido,
                status = :status,
                updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':codigo' => $codigo,
            ':nome' => $nome,
            ':sigla' => $sigla,
            ':descricao' => $descricao,
            ':nivel_id' => $nivel_id,
            ':duracao_meses' => $duracao_meses,
            ':duracao_anos' => $duracao_anos,
            ':carga_horaria_total' => $carga_horaria_total,
            ':valor_mensalidade' => $valor_mensalidade,
            ':requisitos' => $requisitos,
            ':certificado_emitido' => $certificado_emitido,
            ':status' => $status,
            ':id' => $id,
            ':escola_id' => $escola_id
        ]);
        
        $mensagem = "Curso atualizado com sucesso!";
    }
}

// Excluir curso
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Verificar se existem turmas associadas a este curso
    $sql_check = "SELECT COUNT(*) as total FROM turmas WHERE curso_id = :curso_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':curso_id' => $id]);
    $total_turmas = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_turmas > 0) {
        $erro = "Não é possível excluir este curso pois existem $total_turmas turmas associadas.";
    } else {
        $sql = "DELETE FROM cursos WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $mensagem = "Curso excluído com sucesso!";
    }
}

// ============================================
// BUSCAR NÍVEIS DE ENSINO (tabela niveis)
// ============================================
$sql_niveis = "SELECT id, nome, sigla, descricao, ordem, idade_minima, idade_maxima, duracao_anos 
               FROM niveis 
               WHERE escola_id = :escola_id AND status = 'ativo' 
               ORDER BY ordem ASC, nome ASC";
$stmt_niveis = $conn->prepare($sql_niveis);
$stmt_niveis->execute([':escola_id' => $escola_id]);
$niveis = $stmt_niveis->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR CURSOS
// ============================================
$sql_cursos = "SELECT c.*, n.nome as nivel_nome, n.sigla as nivel_sigla
               FROM cursos c
               LEFT JOIN niveis n ON n.id = c.nivel_id
               WHERE c.escola_id = :escola_id 
               ORDER BY c.nome ASC";
$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->execute([':escola_id' => $escola_id]);
$cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

$total_cursos = count($cursos);
$cursos_ativos = 0;
foreach ($cursos as $c) {
    if ($c['status'] == 1) $cursos_ativos++;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cursos - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); padding: 20px; min-height: 100vh; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1e5799 0%, #2c3e50 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 24px;
            border-radius: 40px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            font-weight: 600;
        }
        .btn-voltar:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); color: white; }
        
        .card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            font-weight: bold;
            font-size: 16px;
        }
        .card-body { padding: 25px; }
        
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
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 1px; }
        .stat-total .stat-number { color: #1e5799; }
        .stat-ativos .stat-number { color: #27ae60; }
        
        .btn-novo {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        .btn-novo:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        
        .table-cursos { width: 100%; border-collapse: collapse; }
        .table-cursos th {
            background: #f8f9fa;
            padding: 15px 12px;
            text-align: center;
            font-weight: 700;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-cursos td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-cursos tr:hover { background: #f8f9fa; }
        
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        
        .btn-acao {
            padding: 5px 10px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.3s ease;
            margin: 0 2px;
        }
        .btn-editar { background: #17a2b8; color: white; }
        .btn-editar:hover { background: #138496; transform: translateY(-2px); }
        .btn-excluir { background: #dc3545; color: white; }
        .btn-excluir:hover { background: #c82333; transform: translateY(-2px); }
        
        .modal-custom {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-custom-content {
            background: white;
            margin: 5% auto;
            width: 90%;
            max-width: 700px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-custom-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-custom-header h3 { font-size: 20px; margin: 0; }
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        .close-modal:hover { color: #ddd; }
        .modal-custom-body { padding: 25px; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { font-weight: 600; font-size: 13px; color: #2c3e50; margin-bottom: 8px; display: block; }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #1e5799;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30,87,153,0.1);
        }
        textarea.form-control { resize: vertical; min-height: 80px; }
        
        .btn-salvar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-salvar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        .btn-cancelar {
            background: #6c757d;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-right: 10px;
        }
        .btn-cancelar:hover { transform: translateY(-2px); background: #5a6268; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            display: none;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .table-cursos { font-size: 11px; }
            .table-cursos th, .table-cursos td { padding: 8px; }
            .modal-custom-content { width: 95%; margin: 10% auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-graduation-cap"></i> Cursos</h1>
            <p>Gestão dos cursos oferecidos pela escola</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <?php if (isset($mensagem)): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if (isset($erro)): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo $total_cursos; ?></div>
            <div class="stat-label">Total de Cursos</div>
        </div>
        <div class="stat-card stat-ativos">
            <div class="stat-number"><?php echo $cursos_ativos; ?></div>
            <div class="stat-label">Cursos Ativos</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $total_cursos - $cursos_ativos; ?></div>
            <div class="stat-label">Cursos Inativos</div>
        </div>
    </div>
    
    <!-- Lista de Cursos -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Cursos
            <span class="badge bg-light text-dark ms-2"><?php echo $total_cursos; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Novo Curso</button>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($cursos)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-graduation-cap fa-3x mb-3"></i>
                    <p>Nenhum curso cadastrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeiro curso</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-cursos">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Curso</th>
                                <th>Nível</th>
                                <th>Duração</th>
                                <th>Carga Horária</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cursos as $curso): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($curso['codigo']); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($curso['nome']); ?></strong>
                                        <?php if (!empty($curso['sigla'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($curso['sigla']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($curso['nivel_nome'] ?? '-'); ?> <?php echo !empty($curso['nivel_sigla']) ? '(' . htmlspecialchars($curso['nivel_sigla']) . ')' : ''; ?></td>
                                    <td>
                                        <?php 
                                        $duracao = '';
                                        if ($curso['duracao_anos']) $duracao .= $curso['duracao_anos'] . ' ano(s)';
                                        if ($curso['duracao_meses']) $duracao .= ($duracao ? ' e ' : '') . $curso['duracao_meses'] . ' mês(es)';
                                        echo $duracao ?: '-';
                                        ?>
                                    </td>
                                    <td><?php echo $curso['carga_horaria_total'] ? number_format($curso['carga_horaria_total'], 0) . ' h' : '-'; ?></td>
                                    <td>
                                        <?php if ($curso['status'] == 1): ?>
                                            <span class="badge-status badge-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-inativo">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-acao btn-editar" onclick="editarCurso(<?php echo $curso['id']; ?>)">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" onclick="excluirCurso(<?php echo $curso['id']; ?>, '<?php echo htmlspecialchars($curso['nome']); ?>')">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Novo/Editar Curso -->
<div id="modalCurso" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Curso</h3>
            <span class="close-modal" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formCurso">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="curso_id" value="0">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Código do Curso *</label>
                            <input type="text" name="codigo" class="form-control" required placeholder="Ex: TEC001">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Sigla</label>
                            <input type="text" name="sigla" class="form-control" placeholder="Ex: TEC.INF">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label">Nome do Curso *</label>
                            <input type="text" name="nome" class="form-control" required placeholder="Ex: Técnico em Informática">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Nível de Ensino</label>
                            <select name="nivel_id" class="form-select">
                                <option value="">Selecione</option>
                                <?php foreach ($niveis as $nivel): ?>
                                    <option value="<?php echo $nivel['id']; ?>"><?php echo htmlspecialchars($nivel['nome']); ?> (<?php echo htmlspecialchars($nivel['sigla']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="1">Ativo</option>
                                <option value="0">Inativo</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Duração (anos)</label>
                            <input type="number" name="duracao_anos" class="form-control" placeholder="Ex: 3" step="1" min="0">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Duração (meses)</label>
                            <input type="number" name="duracao_meses" class="form-control" placeholder="Ex: 6" step="1" min="0">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Carga Horária Total (horas)</label>
                            <input type="number" name="carga_horaria_total" class="form-control" placeholder="Ex: 1200">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Valor da Mensalidade (Kz)</label>
                            <input type="text" name="valor_mensalidade" class="form-control" placeholder="Ex: 25000,00">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Requisitos</label>
                    <textarea name="requisitos" class="form-control" rows="2" placeholder="Requisitos para ingresso no curso"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Certificado Emitido</label>
                    <textarea name="certificado_emitido" class="form-control" rows="2" placeholder="Tipo de certificado emitido ao concluir"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="3" placeholder="Descrição detalhada do curso"></textarea>
                </div>
                
                <div class="text-end">
                    <button type="button" class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="btn-salvar"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function showToast(message, isError = false) {
        const toast = document.getElementById('toastMessage');
        toast.textContent = message;
        toast.style.backgroundColor = isError ? '#e74c3c' : '#27ae60';
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Curso';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('curso_id').value = '0';
        document.getElementById('formCurso').reset();
        // Definir valores padrão
        document.querySelector('select[name="status"]').value = '1';
        document.getElementById('modalCurso').style.display = 'block';
    }
    
    function editarCurso(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Curso';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('curso_id').value = id;
        
        showToast('Carregando dados do curso...');
        
        // Usar XMLHttpRequest para melhor debug
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `ajax_buscar_curso.php?id=${id}`, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            const c = data.curso;
                            document.querySelector('input[name="codigo"]').value = c.codigo || '';
                            document.querySelector('input[name="nome"]').value = c.nome || '';
                            document.querySelector('input[name="sigla"]').value = c.sigla || '';
                            document.querySelector('select[name="nivel_id"]').value = c.nivel_id || '';
                            document.querySelector('select[name="status"]').value = c.status !== undefined ? c.status : '1';
                            document.querySelector('input[name="duracao_anos"]').value = c.duracao_anos || '';
                            document.querySelector('input[name="duracao_meses"]').value = c.duracao_meses || '';
                            document.querySelector('input[name="carga_horaria_total"]').value = c.carga_horaria_total || '';
                            document.querySelector('input[name="valor_mensalidade"]').value = c.valor_mensalidade || '';
                            document.querySelector('textarea[name="requisitos"]').value = c.requisitos || '';
                            document.querySelector('textarea[name="certificado_emitido"]').value = c.certificado_emitido || '';
                            document.querySelector('textarea[name="descricao"]').value = c.descricao || '';
                            document.getElementById('modalCurso').style.display = 'block';
                        } else {
                            showModalErroGlobal(data.message || 'Erro ao carregar dados do curso');
                        }
                    } catch (e) {
                        console.error('Erro ao parsear JSON:', e);
                        console.error('Resposta recebida:', xhr.responseText);
                        showModalErroGlobal('Erro ao processar resposta do servidor');
                    }
                } else {
                    showModalErroGlobal('Erro HTTP: ' + xhr.status);
                }
            }
        };
        xhr.onerror = function() {
            showModalErroGlobal('Erro de conexão');
        };
        xhr.send();
    }
    
    function excluirCurso(id, nome) {
        showModalConfirmacaoGlobal(
            '<i class="fas fa-trash"></i> Confirmar Exclusão',
            `Tem certeza que deseja excluir o curso <strong>${nome}</strong>?`,
            function() {
                window.location.href = `?action=excluir&id=${id}`;
            },
            '<i class="fas fa-exclamation-triangle"></i> Esta ação não pode ser desfeita. Cursos com turmas associadas não podem ser excluídos.'
        );
    }
    
    function fecharModal() {
        document.getElementById('modalCurso').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalCurso');
        if (event.target == modal) {
            fecharModal();
        }
    }
</script>
</body>
</html>