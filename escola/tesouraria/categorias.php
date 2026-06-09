<?php
// escola/tesouraria/categorias.php - Gestão de Categorias Financeiras

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

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Inserir nova categoria
    if ($_POST['action'] == 'insert') {
        $tipo = $_POST['tipo'];
        $nome = trim($_POST['nome']);
        $icone = trim($_POST['icone']);
        $cor = trim($_POST['cor']);
        $descricao = trim($_POST['descricao']);
        $ordem = (int)$_POST['ordem'];
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        if (empty($nome)) {
            $error = "Nome da categoria é obrigatório.";
        } else {
 // Verificar se a categoria já existe
        $sql_check = "SELECT id FROM categorias_despesas 
                      WHERE (escola_id = :escola_id OR escola_id IS NULL) 
                      AND nome = :nome";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome
        ]);
        
        if ($stmt_check->fetch()) {
            $error = "Categoria '$nome' já existe!";
        } else {
            try {
                $sql = "INSERT INTO categorias_financeiras (escola_id, tipo, nome, icone, cor, descricao, ordem, ativo, created_at) 
                        VALUES (:escola_id, :tipo, :nome, :icone, :cor, :descricao, :ordem, :ativo, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':tipo' => $tipo,
                    ':nome' => $nome,
                    ':icone' => $icone,
                    ':cor' => $cor,
                    ':descricao' => $descricao,
                    ':ordem' => $ordem,
                    ':ativo' => $ativo
                ]);
                $success = "Categoria cadastrada com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao cadastrar: " . $e->getMessage();
            }
        }
        }
    }
    
    // Atualizar categoria
    elseif ($_POST['action'] == 'update') {
        $id = (int)$_POST['id'];
        $tipo = $_POST['tipo'];
        $nome = trim($_POST['nome']);
        $icone = trim($_POST['icone']);
        $cor = trim($_POST['cor']);
        $descricao = trim($_POST['descricao']);
        $ordem = (int)$_POST['ordem'];
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        try {
            $sql = "UPDATE categorias_financeiras 
                    SET tipo = :tipo, nome = :nome, icone = :icone, cor = :cor, 
                        descricao = :descricao, ordem = :ordem, ativo = :ativo, updated_at = NOW()
                    WHERE id = :id AND (escola_id = :escola_id OR escola_id IS NULL)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':escola_id' => $escola_id,
                ':tipo' => $tipo,
                ':nome' => $nome,
                ':icone' => $icone,
                ':cor' => $cor,
                ':descricao' => $descricao,
                ':ordem' => $ordem,
                ':ativo' => $ativo
            ]);
            $success = "Categoria atualizada com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao atualizar: " . $e->getMessage();
        }
    }
    
    // Excluir categoria
    elseif ($_POST['action'] == 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            // Verificar se já existem movimentações com esta categoria
            $sql_check = "SELECT COUNT(*) as total FROM caixa WHERE categoria = (SELECT nome FROM categorias_financeiras WHERE id = :id) AND escola_id = :escola_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':id' => $id, ':escola_id' => $escola_id]);
            $count = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($count > 0) {
                $error = "Não é possível excluir esta categoria pois existem $count movimentações associadas a ela.";
            } else {
                $sql = "DELETE FROM categorias_financeiras WHERE id = :id AND (escola_id = :escola_id OR escola_id IS NULL)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
                $success = "Categoria excluída com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao excluir: " . $e->getMessage();
        }
    }
    
    // Restaurar categorias padrão
    elseif ($_POST['action'] == 'restaurar_padrao') {
        try {
            // Remover categorias existentes da escola
            $sql_delete = "DELETE FROM categorias_financeiras WHERE escola_id = :escola_id";
            $stmt_delete = $conn->prepare($sql_delete);
            $stmt_delete->execute([':escola_id' => $escola_id]);
            
            // Inserir categorias padrão
            $categorias_padrao = [
                // Receitas
                ['tipo' => 'receita', 'nome' => 'Mensalidade', 'icone' => 'fa-calendar-dollar', 'cor' => '#006B3E', 'ordem' => 1],
                ['tipo' => 'receita', 'nome' => 'Matrícula', 'icone' => 'fa-user-graduate', 'cor' => '#28a745', 'ordem' => 2],
                ['tipo' => 'receita', 'nome' => 'Certificado', 'icone' => 'fa-certificate', 'cor' => '#17a2b8', 'ordem' => 3],
                ['tipo' => 'receita', 'nome' => 'Material Escolar', 'icone' => 'fa-book', 'cor' => '#ffc107', 'ordem' => 4],
                ['tipo' => 'receita', 'nome' => 'Doação', 'icone' => 'fa-gift', 'cor' => '#fd7e14', 'ordem' => 5],
                ['tipo' => 'receita', 'nome' => 'Evento', 'icone' => 'fa-calendar-alt', 'cor' => '#6f42c1', 'ordem' => 6],
                ['tipo' => 'receita', 'nome' => 'Curso Extra', 'icone' => 'fa-chalkboard', 'cor' => '#20c997', 'ordem' => 7],
                ['tipo' => 'receita', 'nome' => 'Outra Receita', 'icone' => 'fa-plus-circle', 'cor' => '#6c757d', 'ordem' => 8],
                // Despesas
                ['tipo' => 'despesa', 'nome' => 'Salários', 'icone' => 'fa-users', 'cor' => '#dc3545', 'ordem' => 1],
                ['tipo' => 'despesa', 'nome' => 'Água', 'icone' => 'fa-tint', 'cor' => '#17a2b8', 'ordem' => 2],
                ['tipo' => 'despesa', 'nome' => 'Luz', 'icone' => 'fa-bolt', 'cor' => '#ffc107', 'ordem' => 3],
                ['tipo' => 'despesa', 'nome' => 'Internet', 'icone' => 'fa-wifi', 'cor' => '#6f42c1', 'ordem' => 4],
                ['tipo' => 'despesa', 'nome' => 'Telefone', 'icone' => 'fa-phone', 'cor' => '#fd7e14', 'ordem' => 5],
                ['tipo' => 'despesa', 'nome' => 'Material de Limpeza', 'icone' => 'fa-broom', 'cor' => '#20c997', 'ordem' => 6],
                ['tipo' => 'despesa', 'nome' => 'Material Escritório', 'icone' => 'fa-pen', 'cor' => '#6c757d', 'ordem' => 7],
                ['tipo' => 'despesa', 'nome' => 'Manutenção', 'icone' => 'fa-wrench', 'cor' => '#fd7e14', 'ordem' => 8],
                ['tipo' => 'despesa', 'nome' => 'Impostos', 'icone' => 'fa-file-invoice', 'cor' => '#dc3545', 'ordem' => 9],
                ['tipo' => 'despesa', 'nome' => 'Outra Despesa', 'icone' => 'fa-minus-circle', 'cor' => '#6c757d', 'ordem' => 10]
            ];
            
            foreach ($categorias_padrao as $cat) {
                $sql_insert = "INSERT INTO categorias_financeiras (escola_id, tipo, nome, icone, cor, ordem, ativo, created_at) 
                               VALUES (:escola_id, :tipo, :nome, :icone, :cor, :ordem, 1, NOW())";
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->execute([
                    ':escola_id' => $escola_id,
                    ':tipo' => $cat['tipo'],
                    ':nome' => $cat['nome'],
                    ':icone' => $cat['icone'],
                    ':cor' => $cat['cor'],
                    ':ordem' => $cat['ordem']
                ]);
            }
            $success = "Categorias padrão restauradas com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao restaurar categorias: " . $e->getMessage();
        }
    }
}

// ============================================
// BUSCAR CATEGORIAS
// ============================================

// Buscar categorias de receita
$sql_receitas = "SELECT * FROM categorias_financeiras 
                 WHERE (escola_id = :escola_id OR escola_id IS NULL) 
                 AND tipo = 'receita'
                 ORDER BY ordem ASC, nome ASC";
$stmt_receitas = $conn->prepare($sql_receitas);
$stmt_receitas->execute([':escola_id' => $escola_id]);
$categorias_receita = $stmt_receitas->fetchAll(PDO::FETCH_ASSOC);

// Buscar categorias de despesa
$sql_despesas = "SELECT * FROM categorias_financeiras 
                 WHERE (escola_id = :escola_id OR escola_id IS NULL) 
                 AND tipo = 'despesa'
                 ORDER BY ordem ASC, nome ASC";
$stmt_despesas = $conn->prepare($sql_despesas);
$stmt_despesas->execute([':escola_id' => $escola_id]);
$categorias_despesa = $stmt_despesas->fetchAll(PDO::FETCH_ASSOC);

// Lista de ícones disponíveis
$icones_disponiveis = [
    'fa-calendar-dollar', 'fa-user-graduate', 'fa-certificate', 'fa-book', 'fa-gift',
    'fa-calendar-alt', 'fa-chalkboard', 'fa-plus-circle', 'fa-users', 'fa-tint',
    'fa-bolt', 'fa-wifi', 'fa-phone', 'fa-broom', 'fa-pen', 'fa-wrench',
    'fa-file-invoice', 'fa-minus-circle', 'fa-money-bill-wave', 'fa-coins',
    'fa-chart-line', 'fa-chart-bar', 'fa-chart-pie', 'fa-tag', 'fa-tags',
    'fa-box', 'fa-truck', 'fa-shopping-cart', 'fa-credit-card', 'fa-university'
];

// Cores disponíveis
$cores_disponiveis = [
    '#006B3E' => 'Verde Escuro', '#28a745' => 'Verde', '#20c997' => 'Verde Água',
    '#007bff' => 'Azul', '#17a2b8' => 'Azul Claro', '#1A2A6C' => 'Azul Escuro',
    '#dc3545' => 'Vermelho', '#fd7e14' => 'Laranja', '#ffc107' => 'Amarelo',
    '#6f42c1' => 'Roxo', '#e83e8c' => 'Rosa', '#6c757d' => 'Cinza'
];

function getTipoBadge($tipo) {
    if ($tipo == 'receita') {
        return '<span class="badge bg-success"><i class="fas fa-arrow-up"></i> Receita</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-arrow-down"></i> Despesa</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorias Financeiras | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .categoria-card { transition: all 0.2s; margin-bottom: 15px; }
        .categoria-card:hover { transform: translateX(5px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        
        .badge-ativo { background: #28a745; color: white; padding: 3px 8px; border-radius: 20px; font-size: 0.7rem; }
        .badge-inativo { background: #dc3545; color: white; padding: 3px 8px; border-radius: 20px; font-size: 0.7rem; }
        
        .icone-preview { font-size: 1.2rem; width: 35px; text-align: center; }
        .cor-preview { width: 30px; height: 30px; border-radius: 5px; display: inline-block; }
        
        .icone-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 8px;
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .icone-option {
            text-align: center;
            padding: 8px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .icone-option:hover {
            background: #e9ecef;
            transform: scale(1.05);
        }
        .icone-option.selected {
            background: #006B3E;
            color: white;
        }
        .icone-option i { font-size: 1.2rem; }
        .icone-option span { font-size: 0.7rem; display: block; margin-top: 4px; }
        
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-tags"></i> Categorias Financeiras</h2>
                <p class="text-muted">Gerenciar categorias de receitas e despesas</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaCategoria">
                    <i class="fas fa-plus"></i> Nova Categoria
                </button>
                <button class="btn btn-warning ms-2" onclick="restaurarPadrao()">
                    <i class="fas fa-undo-alt"></i> Restaurar Padrão
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#receitas" type="button" role="tab">
                    <i class="fas fa-arrow-up text-success"></i> Receitas
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#despesas" type="button" role="tab">
                    <i class="fas fa-arrow-down text-danger"></i> Despesas
                </button>
            </li>
        </ul>
        
        <!-- Conteúdo das Tabs -->
        <div class="tab-content">
            <!-- Receitas -->
            <div class="tab-pane fade show active" id="receitas" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-tag"></i> Categorias de Receitas</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($categorias_receita)): ?>
                            <div class="alert alert-info text-center">Nenhuma categoria de receita cadastrada.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Ícone</th>
                                            <th>Nome</th>
                                            <th>Descrição</th>
                                            <th>Cor</th>
                                            <th>Ordem</th>
                                            <th>Status</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categorias_receita as $cat): ?>
                                        <tr style="border-left: 4px solid <?php echo $cat['cor']; ?>;">
                                            <td><?php echo $cat['id']; ?></td>
                                            <td class="text-center"><i class="fas <?php echo $cat['icone']; ?>" style="color: <?php echo $cat['cor']; ?>; font-size: 1.2rem;"></i></td>
                                            <td><strong><?php echo htmlspecialchars($cat['nome']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($cat['descricao'] ?: '-'); ?></small></td>
                                            <td>
                                                <div class="cor-preview" style="background: <?php echo $cat['cor']; ?>;"></div>
                                                <small><?php echo $cat['cor']; ?></small>
                                            </td>
                                            <td><?php echo $cat['ordem']; ?></td>
                                            <td>
                                                <?php if ($cat['ativo']): ?>
                                                    <span class="badge-ativo">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge-inativo">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-warning" onclick="editarCategoria(<?php echo htmlspecialchars(json_encode($cat)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="excluirCategoria(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars($cat['nome']); ?>')">
                                                    <i class="fas fa-trash"></i>
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
            
            <!-- Despesas -->
            <div class="tab-pane fade" id="despesas" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-tag"></i> Categorias de Despesas</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($categorias_despesa)): ?>
                            <div class="alert alert-info text-center">Nenhuma categoria de despesa cadastrada.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Ícone</th>
                                            <th>Nome</th>
                                            <th>Descrição</th>
                                            <th>Cor</th>
                                            <th>Ordem</th>
                                            <th>Status</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categorias_despesa as $cat): ?>
                                        <tr style="border-left: 4px solid <?php echo $cat['cor']; ?>;">
                                            <td><?php echo $cat['id']; ?></td>
                                            <td class="text-center"><i class="fas <?php echo $cat['icone']; ?>" style="color: <?php echo $cat['cor']; ?>; font-size: 1.2rem;"></i></td>
                                            <td><strong><?php echo htmlspecialchars($cat['nome']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($cat['descricao'] ?: '-'); ?></small></td>
                                            <td>
                                                <div class="cor-preview" style="background: <?php echo $cat['cor']; ?>;"></div>
                                                <small><?php echo $cat['cor']; ?></small>
                                            </td>
                                            <td><?php echo $cat['ordem']; ?></td>
                                            <td>
                                                <?php if ($cat['ativo']): ?>
                                                    <span class="badge-ativo">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge-inativo">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-warning" onclick="editarCategoria(<?php echo htmlspecialchars(json_encode($cat)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="excluirCategoria(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars($cat['nome']); ?>')">
                                                    <i class="fas fa-trash"></i>
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
        </div>
        
        <!-- Informações -->
        <div class="alert alert-info mt-4">
            <i class="fas fa-info-circle"></i>
            <strong>Informação:</strong> As categorias são utilizadas para classificar receitas e despesas no caixa. 
            A ordem define a posição de exibição nos formulários. Categorias inativas não aparecem nas opções de seleção.
        </div>
    </div>
    
    <!-- Modal Nova Categoria -->
    <div class="modal fade" id="modalNovaCategoria" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nova Categoria</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo <span class="text-danger">*</span></label>
                                <select name="tipo" class="form-select" required>
                                    <option value="receita">📥 Receita (Entrada)</option>
                                    <option value="despesa">📤 Despesa (Saída)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome <span class="text-danger">*</span></label>
                                <input type="text" name="nome" class="form-control" required placeholder="Ex: Mensalidade, Salários...">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2" placeholder="Descrição da categoria..."></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ícone <span class="text-danger">*</span></label>
                                <input type="text" name="icone" id="icone_novo" class="form-control" required placeholder="fa-tag" value="fa-tag">
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#iconesListaNovo">
                                        <i class="fas fa-icons"></i> Escolher Ícone
                                    </button>
                                </div>
                                <div class="collapse mt-2" id="iconesListaNovo">
                                    <div class="icone-grid">
                                        <?php foreach ($icones_disponiveis as $icone): ?>
                                        <div class="icone-option" onclick="document.getElementById('icone_novo').value='<?php echo $icone; ?>'; this.closest('.collapse').classList.remove('show');">
                                            <i class="fas <?php echo $icone; ?>"></i>
                                            <span><?php echo $icone; ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cor <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <input type="color" name="cor" id="cor_novo" class="form-control form-control-color w-25" value="#006B3E">
                                    <input type="text" class="form-control" id="cor_texto_novo" value="#006B3E" readonly>
                                </div>
                                <div class="mt-2">
                                    <select class="form-select" onchange="document.getElementById('cor_novo').value=this.value; document.getElementById('cor_texto_novo').value=this.value;">
                                        <option value="">Cores pré-definidas</option>
                                        <?php foreach ($cores_disponiveis as $cor => $nome): ?>
                                        <option value="<?php echo $cor; ?>" style="background: <?php echo $cor; ?>; color: white;"><?php echo $nome; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ordem de Exibição</label>
                                <input type="number" name="ordem" class="form-control" value="0" placeholder="Número da ordem">
                                <small class="text-muted">Menor número aparece primeiro</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">&nbsp;</label>
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="ativo" class="form-check-input" id="ativo_novo" checked>
                                    <label class="form-check-label" for="ativo_novo">Ativo (disponível para uso)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Categoria</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Categoria -->
    <div class="modal fade" id="modalEditarCategoria" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Categoria</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" id="edit_tipo" class="form-select" required>
                                    <option value="receita">📥 Receita (Entrada)</option>
                                    <option value="despesa">📤 Despesa (Saída)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome</label>
                                <input type="text" name="nome" id="edit_nome" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" id="edit_descricao" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ícone</label>
                                <input type="text" name="icone" id="edit_icone" class="form-control" required>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#iconesListaEditar">
                                        <i class="fas fa-icons"></i> Escolher Ícone
                                    </button>
                                </div>
                                <div class="collapse mt-2" id="iconesListaEditar">
                                    <div class="icone-grid">
                                        <?php foreach ($icones_disponiveis as $icone): ?>
                                        <div class="icone-option" onclick="document.getElementById('edit_icone').value='<?php echo $icone; ?>'; this.closest('.collapse').classList.remove('show');">
                                            <i class="fas <?php echo $icone; ?>"></i>
                                            <span><?php echo $icone; ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cor</label>
                                <div class="d-flex gap-2">
                                    <input type="color" name="cor" id="edit_cor" class="form-control form-control-color w-25">
                                    <input type="text" class="form-control" id="edit_cor_texto" readonly>
                                </div>
                                <div class="mt-2">
                                    <select class="form-select" onchange="document.getElementById('edit_cor').value=this.value; document.getElementById('edit_cor_texto').value=this.value;">
                                        <option value="">Cores pré-definidas</option>
                                        <?php foreach ($cores_disponiveis as $cor => $nome): ?>
                                        <option value="<?php echo $cor; ?>" style="background: <?php echo $cor; ?>;"><?php echo $nome; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ordem de Exibição</label>
                                <input type="number" name="ordem" id="edit_ordem" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="ativo" class="form-check-input" id="edit_ativo">
                                    <label class="form-check-label" for="edit_ativo">Ativo (disponível para uso)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Atualizar Categoria</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Excluir -->
    <div class="modal fade" id="modalExcluir" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="modal-body">
                        <p>Tem certeza que deseja excluir a categoria <strong id="delete_nome"></strong>?</p>
                        <p class="text-danger"><small>Esta ação não pode ser desfeita.</small></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Excluir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Sincronizar input de cor
        document.getElementById('cor_novo')?.addEventListener('change', function() {
            document.getElementById('cor_texto_novo').value = this.value;
        });
        
        document.getElementById('edit_cor')?.addEventListener('change', function() {
            document.getElementById('edit_cor_texto').value = this.value;
        });
        
        function editarCategoria(cat) {
            document.getElementById('edit_id').value = cat.id;
            document.getElementById('edit_tipo').value = cat.tipo;
            document.getElementById('edit_nome').value = cat.nome;
            document.getElementById('edit_descricao').value = cat.descricao || '';
            document.getElementById('edit_icone').value = cat.icone;
            document.getElementById('edit_cor').value = cat.cor;
            document.getElementById('edit_cor_texto').value = cat.cor;
            document.getElementById('edit_ordem').value = cat.ordem;
            document.getElementById('edit_ativo').checked = cat.ativo == 1;
            new bootstrap.Modal(document.getElementById('modalEditarCategoria')).show();
        }
        
        function excluirCategoria(id, nome) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_nome').innerText = nome;
            new bootstrap.Modal(document.getElementById('modalExcluir')).show();
        }
        
        function restaurarPadrao() {
            if(confirm('Tem certeza que deseja restaurar as categorias padrão? Todas as categorias personalizadas serão removidas!')) {
                $('<form method="POST"><input type="hidden" name="action" value="restaurar_padrao"></form>').appendTo('body').submit();
            }
        }
    </script>
</body>
</html>