<?php
// escola/servicos_pedagogicos/gerais/cursos.php - Gestão de Cursos

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? '';

// Verificar se tem permissão (apenas admin, diretor ou secretaria)
if (!in_array($usuario_tipo, ['admin_escola', 'diretor', 'secretaria'])) {
    header('Location: ../dashboard.php?erro=Sem permissão');
    exit;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function gerarSigla($nome) {
    // Remove acentos e caracteres especiais
    $nome = preg_replace('/[^a-zA-Z0-9\s]/', '', $nome);
    $palavras = explode(' ', $nome);
    $sigla = '';
    
    if (count($palavras) == 1) {
        $sigla = strtoupper(substr($palavras[0], 0, 3));
    } elseif (count($palavras) == 2) {
        $sigla = strtoupper(substr($palavras[0], 0, 1) . substr($palavras[1], 0, 1));
    } else {
        $sigla = strtoupper(substr($palavras[0], 0, 1) . substr($palavras[1], 0, 1) . substr($palavras[2], 0, 1));
    }
    
    return $sigla;
}

function gerarCodigoCurso($conn, $escola_id, $nome) {
    $nome_clean = iconv('UTF-8', 'ASCII//TRANSLIT', $nome);
    $nome_clean = preg_replace('/[^a-zA-Z0-9]/', '', $nome_clean);
    $prefixo = strtoupper(substr($nome_clean, 0, 3));
    
    $stmt = $conn->prepare("SELECT codigo FROM cursos WHERE escola_id = :escola_id AND codigo LIKE :prefixo ORDER BY id DESC LIMIT 1");
    $stmt->execute([':escola_id' => $escola_id, ':prefixo' => $prefixo . '%']);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo && preg_match('/(\d+)/', $ultimo['codigo'], $matches)) {
        $numero = (int)$matches[1] + 1;
    } else {
        $numero = 1;
    }
    
    return $prefixo . str_pad($numero, 3, '0', STR_PAD_LEFT);
}

// ============================================
// PAGINAÇÃO
// ============================================
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 10;
$offset = ($pagina - 1) * $por_pagina;

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================

// Adicionar novo curso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'adicionar') {
    $nome = $_POST['nome'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $nivel_id = $_POST['nivel_id'] ?? null;
    $duracao_meses = $_POST['duracao_meses'] ?? null;
    $duracao_anos = $_POST['duracao_anos'] ?? null;
    $carga_horaria_total = $_POST['carga_horaria_total'] ?? null;
    $valor_mensalidade = $_POST['valor_mensalidade'] ?? null;
    $requisitos = $_POST['requisitos'] ?? '';
    $certificado_emitido = $_POST['certificado_emitido'] ?? '';
    $status = isset($_POST['status']) ? 1 : 0;
    
    $sigla = gerarSigla($nome);
    $stmt_check = $conn->prepare("SELECT id FROM cursos WHERE sigla = :sigla AND escola_id = :escola_id");
    $stmt_check->execute([':sigla' => $sigla, ':escola_id' => $escola_id]);
    if ($stmt_check->fetch()) {
        $sigla = $sigla . rand(1, 99);
    }
    
    $codigo = gerarCodigoCurso($conn, $escola_id, $nome);
    
    if ($nome) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO cursos (nome, codigo, sigla, descricao, nivel_id, duracao_meses, duracao_anos, 
                                   carga_horaria_total, valor_mensalidade, requisitos, certificado_emitido, 
                                   escola_id, status, created_at)
                VALUES (:nome, :codigo, :sigla, :descricao, :nivel_id, :duracao_meses, :duracao_anos,
                        :carga_horaria_total, :valor_mensalidade, :requisitos, :certificado_emitido,
                        :escola_id, :status, NOW())
            ");
            $stmt->execute([
                ':nome' => $nome,
                ':codigo' => $codigo,
                ':sigla' => $sigla,
                ':descricao' => $descricao,
                ':nivel_id' => $nivel_id ?: null,
                ':duracao_meses' => $duracao_meses ?: null,
                ':duracao_anos' => $duracao_anos ?: null,
                ':carga_horaria_total' => $carga_horaria_total ?: null,
                ':valor_mensalidade' => $valor_mensalidade ?: null,
                ':requisitos' => $requisitos,
                ':certificado_emitido' => $certificado_emitido,
                ':escola_id' => $escola_id,
                ':status' => $status
            ]);
            $_SESSION['success'] = "Curso adicionado com sucesso! Código: " . $codigo . " | Sigla: " . $sigla;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao adicionar curso: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha o campo nome do curso.";
    }
    header('Location: cursos.php');
    exit;
}

// Editar curso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'editar') {
    $id = $_POST['id'] ?? 0;
    $nome = $_POST['nome'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $nivel_id = $_POST['nivel_id'] ?? null;
    $duracao_meses = $_POST['duracao_meses'] ?? null;
    $duracao_anos = $_POST['duracao_anos'] ?? null;
    $carga_horaria_total = $_POST['carga_horaria_total'] ?? null;
    $valor_mensalidade = $_POST['valor_mensalidade'] ?? null;
    $requisitos = $_POST['requisitos'] ?? '';
    $certificado_emitido = $_POST['certificado_emitido'] ?? '';
    $status = isset($_POST['status']) ? 1 : 0;
    
    if ($id > 0 && $nome) {
        try {
            $stmt = $conn->prepare("
                UPDATE cursos SET 
                    nome = :nome,
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
                WHERE id = :id AND escola_id = :escola_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':nome' => $nome,
                ':descricao' => $descricao,
                ':nivel_id' => $nivel_id ?: null,
                ':duracao_meses' => $duracao_meses ?: null,
                ':duracao_anos' => $duracao_anos ?: null,
                ':carga_horaria_total' => $carga_horaria_total ?: null,
                ':valor_mensalidade' => $valor_mensalidade ?: null,
                ':requisitos' => $requisitos,
                ':certificado_emitido' => $certificado_emitido,
                ':status' => $status,
                ':escola_id' => $escola_id
            ]);
            $_SESSION['success'] = "Curso atualizado com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao atualizar curso: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha os campos obrigatórios.";
    }
    header('Location: cursos.php');
    exit;
}

// Excluir curso (via POST com AJAX ou formulário)
if (isset($_POST['acao']) && $_POST['acao'] == 'excluir' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM cursos WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// BUSCAR DADOS COM PAGINAÇÃO
// ============================================

// Contar total de cursos
$sql_count = "SELECT COUNT(*) as total FROM cursos WHERE escola_id = :escola_id";
$stmt_count = $conn->prepare($sql_count);
$stmt_count->execute([':escola_id' => $escola_id]);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// Buscar cursos com paginação
$sql_cursos = "SELECT c.*, n.nome as nivel_nome 
               FROM cursos c
               LEFT JOIN niveis n ON n.id = c.nivel_id
               WHERE c.escola_id = :escola_id 
               ORDER BY c.nome
               LIMIT :offset, :por_pagina";
$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->bindParam(':escola_id', $escola_id);
$stmt_cursos->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt_cursos->bindParam(':por_pagina', $por_pagina, PDO::PARAM_INT);
$stmt_cursos->execute();
$cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

// Buscar curso específico para edição
$curso_editar = null;
if (isset($_GET['editar']) && isset($_GET['id'])) {
    $id_editar = (int)$_GET['id'];
    $stmt_editar = $conn->prepare("SELECT * FROM cursos WHERE id = :id AND escola_id = :escola_id");
    $stmt_editar->execute([':id' => $id_editar, ':escola_id' => $escola_id]);
    $curso_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}

// Buscar níveis para o select
$sql_niveis = "SELECT id, nome FROM niveis WHERE status = 1 ORDER BY ordem";
$niveis = $conn->query($sql_niveis)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Cursos | Serviços Pedagógicos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-warning { background: #ffc107; border: none; }
        .btn-danger { background: #dc3545; border: none; }
        .table th { background: #f8f9fa; }
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .status-ativo { background: #d4edda; color: #155724; }
        .status-inativo { background: #f8d7da; color: #721c24; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .form-label { font-weight: 600; }
        .codigo-badge, .sigla-badge { background: #e9ecef; padding: 4px 10px; border-radius: 15px; font-family: monospace; font-size: 12px; font-weight: bold; display: inline-block; margin-right: 5px; }
        .valor-destaque { color: #006B3E; font-weight: bold; }
        .pagination .page-link { color: #006B3E; }
        .pagination .active .page-link { background-color: #006B3E; border-color: #006B3E; color: white; }
    </style>
</head>
<body>
    
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-graduation-cap"></i> Gestão de Cursos</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCurso" onclick="resetForm()">
                <i class="fas fa-plus"></i> Novo Curso
            </button>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Cursos Cadastrados</h5>
                <small class="text-white-50">Total: <?php echo $total_registros; ?> cursos</small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nome</th>
                                <th>Sigla</th>
                                <th>Nível</th>
                                <th>Duração</th>
                                <th>Carga Horária</th>
                                <th>Mensalidade</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cursos)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-graduation-cap fa-2x mb-2 d-block"></i>
                                        Nenhum curso cadastrado. Clique em "Novo Curso" para adicionar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cursos as $curso): ?>
                                <tr>
                                    <td><span class="codigo-badge"><?php echo htmlspecialchars($curso['codigo']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($curso['nome']); ?></strong></td>
                                    <td><span class="sigla-badge"><?php echo htmlspecialchars($curso['sigla']); ?></span></td>
                                    <td><?php echo htmlspecialchars($curso['nivel_nome'] ?? '-'); ?></td>
                                    <td>
                                        <?php 
                                        if ($curso['duracao_anos'] && $curso['duracao_meses']) {
                                            echo $curso['duracao_anos'] . ' ano(s) e ' . $curso['duracao_meses'] . ' mês(es)';
                                        } elseif ($curso['duracao_anos']) {
                                            echo $curso['duracao_anos'] . ' ano(s)';
                                        } elseif ($curso['duracao_meses']) {
                                            echo $curso['duracao_meses'] . ' mês(es)';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo $curso['carga_horaria_total'] ? number_format($curso['carga_horaria_total'], 0) . 'h' : '-'; ?></td>
                                    <td>
                                        <?php if ($curso['valor_mensalidade']): ?>
                                            <span class="valor-destaque"><?php echo number_format($curso['valor_mensalidade'], 2); ?> Kz</span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($curso['status']): ?>
                                            <span class="status-badge status-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inativo">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="?editar=1&id=<?php echo $curso['id']; ?>" class="btn btn-sm btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-danger" title="Excluir" onclick="confirmarExclusao(<?php echo $curso['id']; ?>, '<?php echo addslashes($curso['nome']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginação -->
                <?php if ($total_paginas > 1): ?>
                <nav aria-label="Paginação" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?pagina=<?php echo $pagina - 1; ?>">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        </li>
                        
                        <?php
                        $inicio = max(1, $pagina - 2);
                        $fim = min($total_paginas, $pagina + 2);
                        for ($i = $inicio; $i <= $fim; $i++):
                        ?>
                        <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                            <a class="page-link" href="?pagina=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?pagina=<?php echo $pagina + 1; ?>">
                                Próximo <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Adicionar/Editar Curso -->
    <div class="modal fade" id="modalCurso" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-graduation-cap"></i> Adicionar Curso</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formCurso">
                    <input type="hidden" name="id" id="curso_id" value="">
                    <input type="hidden" name="acao" id="acao" value="adicionar">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label required">Nome do Curso</label>
                                <input type="text" name="nome" id="nome" class="form-control" required placeholder="Ex: Informática, Administração, Enfermagem" onkeyup="gerarPreview()">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Código</label>
                                <input type="text" id="codigo_preview" class="form-control" readonly disabled style="background: #e9ecef; font-family: monospace;">
                                <small class="text-muted">Código gerado automaticamente</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sigla</label>
                                <input type="text" id="sigla_preview" class="form-control" readonly disabled style="background: #e9ecef; font-family: monospace;">
                                <small class="text-muted">Sigla gerada automaticamente</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nível de Ensino</label>
                                <select name="nivel_id" id="nivel_id" class="form-control">
                                    <option value="">Selecione o nível...</option>
                                    <?php foreach ($niveis as $nivel): ?>
                                    <option value="<?php echo $nivel['id']; ?>"><?php echo htmlspecialchars($nivel['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Certificado Emitido</label>
                                <input type="text" name="certificado_emitido" id="certificado_emitido" class="form-control" placeholder="Ex: Técnico de Informática, Bacharel em Administração">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duração (anos)</label>
                                <input type="number" name="duracao_anos" id="duracao_anos" class="form-control" min="0" max="10" step="1" placeholder="Ex: 3">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duração (meses)</label>
                                <input type="number" name="duracao_meses" id="duracao_meses" class="form-control" min="0" max="12" step="1" placeholder="Ex: 6">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Carga Horária Total (horas)</label>
                                <input type="number" name="carga_horaria_total" id="carga_horaria_total" class="form-control" min="0" step="1" placeholder="Ex: 1200">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valor da Mensalidade (Kz)</label>
                                <input type="number" name="valor_mensalidade" id="valor_mensalidade" class="form-control" min="0" step="100" placeholder="Ex: 15000">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Requisitos de Acesso</label>
                            <textarea name="requisitos" id="requisitos" class="form-control" rows="2" placeholder="Ex: Ensino Médio completo, conhecimentos básicos de informática"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição do Curso</label>
                            <textarea name="descricao" id="descricao" class="form-control" rows="3" placeholder="Descreva o curso, objetivos, etc."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                <label class="form-check-label" for="status">
                                    Curso Ativo
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Curso</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação de Exclusão -->
    <div class="modal fade" id="modalExcluir" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja excluir o curso <strong id="cursoNome"></strong>?</p>
                    <p class="text-danger small">Esta ação não pode ser desfeita. Todos os dados relacionados a este curso serão removidos.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarExcluir">Sim, excluir</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var cursoIdParaExcluir = null;
        
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
        
        function gerarPreview() {
            var nome = $('#nome').val();
            if (nome) {
                var nomeSemAcentos = nome.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                var nomeClean = nomeSemAcentos.replace(/[^a-zA-Z0-9\s]/g, '');
                
                var palavras = nomeClean.split(' ');
                var sigla = '';
                
                if (palavras.length == 1) {
                    sigla = palavras[0].substring(0, 3).toUpperCase();
                } else if (palavras.length == 2) {
                    sigla = palavras[0].charAt(0).toUpperCase() + palavras[1].charAt(0).toUpperCase();
                } else {
                    sigla = palavras[0].charAt(0).toUpperCase() + palavras[1].charAt(0).toUpperCase() + palavras[2].charAt(0).toUpperCase();
                }
                
                $('#sigla_preview').val(sigla);
                
                var prefixo = nomeClean.substring(0, 3).toUpperCase();
                var numeroAleatorio = Math.floor(Math.random() * 900) + 100;
                $('#codigo_preview').val(prefixo + numeroAleatorio);
            } else {
                $('#sigla_preview').val('Será gerado automaticamente');
                $('#codigo_preview').val('Será gerado automaticamente');
            }
        }
        
        $('#nome').on('keyup', gerarPreview);
        
        function resetForm() {
            $('#formCurso')[0].reset();
            $('#acao').val('adicionar');
            $('#modalTitle').html('<i class="fas fa-graduation-cap"></i> Adicionar Curso');
            $('#curso_id').val('');
            $('#status').prop('checked', true);
            $('#sigla_preview').val('Será gerado automaticamente');
            $('#codigo_preview').val('Será gerado automaticamente');
            $('#nivel_id').val('');
        }
        
        function confirmarExclusao(id, nome) {
            cursoIdParaExcluir = id;
            $('#cursoNome').text(nome);
            $('#modalExcluir').modal('show');
        }
        
        $('#btnConfirmarExcluir').on('click', function() {
            if (cursoIdParaExcluir) {
                $.ajax({
                    url: 'cursos.php',
                    method: 'POST',
                    data: {
                        acao: 'excluir',
                        id: cursoIdParaExcluir
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Erro ao excluir curso: ' + response.error);
                            $('#modalExcluir').modal('hide');
                        }
                    },
                    error: function() {
                        alert('Erro ao processar a requisição.');
                        $('#modalExcluir').modal('hide');
                    }
                });
            }
        });
        
        <?php if ($curso_editar): ?>
        $(document).ready(function() {
            $('#modalTitle').html('<i class="fas fa-edit"></i> Editar Curso');
            $('#acao').val('editar');
            $('#curso_id').val('<?php echo $curso_editar['id']; ?>');
            $('#nome').val('<?php echo addslashes($curso_editar['nome']); ?>');
            $('#codigo_preview').val('<?php echo $curso_editar['codigo']; ?>');
            $('#sigla_preview').val('<?php echo $curso_editar['sigla']; ?>');
            $('#nivel_id').val('<?php echo $curso_editar['nivel_id']; ?>');
            $('#duracao_anos').val('<?php echo $curso_editar['duracao_anos']; ?>');
            $('#duracao_meses').val('<?php echo $curso_editar['duracao_meses']; ?>');
            $('#carga_horaria_total').val('<?php echo $curso_editar['carga_horaria_total']; ?>');
            $('#valor_mensalidade').val('<?php echo $curso_editar['valor_mensalidade']; ?>');
            $('#descricao').val('<?php echo addslashes($curso_editar['descricao']); ?>');
            $('#requisitos').val('<?php echo addslashes($curso_editar['requisitos']); ?>');
            $('#certificado_emitido').val('<?php echo addslashes($curso_editar['certificado_emitido']); ?>');
            $('#status').prop('checked', <?php echo $curso_editar['status'] ? 'true' : 'false'; ?>);
            
            $('#modalCurso').modal('show');
        });
        <?php endif; ?>
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('servicos_pedagogicos')) {
            $('#menuGerais').addClass('open');
            $('#submenuGerais').addClass('show');
        }
    </script>
</body>
</html>