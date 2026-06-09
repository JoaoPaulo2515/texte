<?php
// escola/financeiro/tipos_pagamento.php - Gestão de Tipos de Pagamento

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
    header('Location: ../login.php?msg=acesso_negado');
    exit;
}

// ============================================
// PROCESSAR FORMULÁRIOS (CRUD)
// ============================================
$success = '';
$error = '';

// Inserir novo tipo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'insert') {
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $icone = trim($_POST['icone']);
        $cor = trim($_POST['cor']);
        $ordem = (int)$_POST['ordem'];
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        if (empty($nome)) {
            $error = "O nome do tipo de pagamento é obrigatório.";
        } else {
            try {
                $sql = "INSERT INTO tipos_pagamento (escola_id, nome, descricao, icone, cor, ordem, ativo, created_at) 
                        VALUES (:escola_id, :nome, :descricao, :icone, :cor, :ordem, :ativo, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':nome' => $nome,
                    ':descricao' => $descricao,
                    ':icone' => $icone,
                    ':cor' => $cor,
                    ':ordem' => $ordem,
                    ':ativo' => $ativo
                ]);
                $success = "Tipo de pagamento cadastrado com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao cadastrar: " . $e->getMessage();
            }
        }
    }
    
    // Atualizar tipo
    elseif ($_POST['action'] == 'update') {
        $id = (int)$_POST['id'];
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $icone = trim($_POST['icone']);
        $cor = trim($_POST['cor']);
        $ordem = (int)$_POST['ordem'];
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        if (empty($nome)) {
            $error = "O nome do tipo de pagamento é obrigatório.";
        } else {
            try {
                $sql = "UPDATE tipos_pagamento 
                        SET nome = :nome, descricao = :descricao, icone = :icone, cor = :cor, 
                            ordem = :ordem, ativo = :ativo, updated_at = NOW()
                        WHERE id = :id AND (escola_id = :escola_id OR escola_id IS NULL)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':id' => $id,
                    ':escola_id' => $escola_id,
                    ':nome' => $nome,
                    ':descricao' => $descricao,
                    ':icone' => $icone,
                    ':cor' => $cor,
                    ':ordem' => $ordem,
                    ':ativo' => $ativo
                ]);
                $success = "Tipo de pagamento atualizado com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao atualizar: " . $e->getMessage();
            }
        }
    }
    
    // Excluir tipo
    elseif ($_POST['action'] == 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            // Verificar se já existem pagamentos com este tipo
            $sql_check = "SELECT COUNT(*) as total FROM pagamentos WHERE tipo_pagamento_id = :id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':id' => $id]);
            $count = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($count > 0) {
                $error = "Não é possível excluir este tipo de pagamento pois existem $count pagamentos associados a ele.";
            } else {
                $sql = "DELETE FROM tipos_pagamento WHERE id = :id AND (escola_id = :escola_id OR escola_id IS NULL)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
                $success = "Tipo de pagamento excluído com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao excluir: " . $e->getMessage();
        }
    }
}

// ============================================
// LISTAR TIPOS DE PAGAMENTO
// ============================================
$sql_tipos = "SELECT * FROM tipos_pagamento 
              WHERE escola_id = :escola_id OR escola_id IS NULL 
              ORDER BY ordem ASC, id ASC";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute([':escola_id' => $escola_id]);
$tipos_pagamento = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// LISTA DE ÍCONES DISPONÍVEIS
// ============================================
$icones_disponiveis = [
    'fas fa-tag', 'fas fa-calendar-dollar', 'fas fa-user-graduate', 'fas fa-certificate',
    'fas fa-book', 'fas fa-futbol', 'fas fa-flask', 'fas fa-book-open', 'fas fa-chalkboard-user',
    'fas fa-laptop-code', 'fas fa-paint-brush', 'fas fa-music', 'fas fa-dumbbell',
    'fas fa-car', 'fas fa-utensils', 'fas fa-bed', 'fas fa-wifi', 'fas fa-tv',
    'fas fa-phone', 'fas fa-envelope', 'fas fa-globe', 'fas fa-database', 'fas fa-cloud',
    'fas fa-shield-alt', 'fas fa-lock', 'fas fa-key', 'fas fa-id-card', 'fas fa-address-card',
    'fas fa-file-invoice', 'fas fa-file-invoice-dollar', 'fas fa-receipt', 'fas fa-credit-card',
    'fas fa-money-bill-wave', 'fas fa-coins', 'fas fa-chart-line', 'fas fa-chart-bar',
    'fas fa-chart-pie', 'fas fa-tachometer-alt', 'fas fa-cog', 'fas fa-wrench'
];

// Cores disponíveis
$cores_disponiveis = [
    '#006B3E' => 'Verde Escuro', '#28a745' => 'Verde', '#20c997' => 'Verde Água',
    '#007bff' => 'Azul', '#17a2b8' => 'Azul Claro', '#1A2A6C' => 'Azul Escuro',
    '#dc3545' => 'Vermelho', '#fd7e14' => 'Laranja', '#ffc107' => 'Amarelo',
    '#6f42c1' => 'Roxo', '#e83e8c' => 'Rosa', '#6c757d' => 'Cinza'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tipos de Pagamento | Financeiro | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .tipo-card {
            transition: all 0.2s;
            border-left: 4px solid;
            margin-bottom: 15px;
        }
        .tipo-card:hover { transform: translateX(5px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        
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
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-tags"></i> Tipos de Pagamento</h2>
                <p class="text-muted">Gerenciar os tipos de pagamento disponíveis no sistema</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoTipo">
                    <i class="fas fa-plus"></i> Novo Tipo de Pagamento
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
        
        <!-- Lista de Tipos de Pagamento -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Tipos de Pagamento Cadastrados</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="50">#</th>
                                <th width="60">Ícone</th>
                                <th>Nome</th>
                                <th>Descrição</th>
                                <th width="80">Cor</th>
                                <th width="80">Ordem</th>
                                <th width="80">Status</th>
                                <th width="100">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tipos_pagamento as $tipo): ?>
                            <tr style="border-left: 4px solid <?php echo $tipo['cor']; ?>;">
                                <td><?php echo $tipo['id']; ?></td>
                                <td class="text-center"><i class="<?php echo $tipo['icone']; ?>" style="color: <?php echo $tipo['cor']; ?>; font-size: 1.2rem;"></i></td>
                                <td><strong><?php echo htmlspecialchars($tipo['nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($tipo['descricao']); ?></td>
                                <td>
                                    <div class="cor-preview" style="background: <?php echo $tipo['cor']; ?>;"></div>
                                    <small><?php echo $tipo['cor']; ?></small>
                                </td>
                                <td><?php echo $tipo['ordem']; ?></td>
                                <td>
                                    <?php if ($tipo['ativo']): ?>
                                        <span class="badge-ativo">Ativo</span>
                                    <?php else: ?>
                                        <span class="badge-inativo">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editarTipo(<?php echo htmlspecialchars(json_encode($tipo)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="excluirTipo(<?php echo $tipo['id']; ?>, '<?php echo htmlspecialchars($tipo['nome']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($tipos_pagamento)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">Nenhum tipo de pagamento cadastrado.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Informações -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Informação:</strong> Os tipos de pagamento são utilizados no momento de registrar novos pagamentos. 
            A ordem define a posição de exibição no formulário. Ícones e cores personalizam a aparência.
        </div>
    </div>
    
    <!-- Modal Novo Tipo -->
    <div class="modal fade" id="modalNovoTipo" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Novo Tipo de Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome <span class="text-danger">*</span></label>
                                <input type="text" name="nome" class="form-control" required placeholder="Ex: Mensalidade, Matrícula, etc.">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ordem de Exibição</label>
                                <input type="number" name="ordem" class="form-control" value="0" placeholder="Número da ordem">
                                <small class="text-muted">Menor número aparece primeiro</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2" placeholder="Descrição do tipo de pagamento"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ícone <span class="text-danger">*</span></label>
                                <input type="text" name="icone" id="icone_novo" class="form-control" required placeholder="fas fa-tag" value="fas fa-tag">
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#iconesListaNovo">
                                        <i class="fas fa-icons"></i> Escolher Ícone
                                    </button>
                                </div>
                                <div class="collapse mt-2" id="iconesListaNovo">
                                    <div class="icone-grid">
                                        <?php foreach ($icones_disponiveis as $icone): ?>
                                        <div class="icone-option" onclick="document.getElementById('icone_novo').value='<?php echo $icone; ?>'; this.closest('.collapse').classList.remove('show');">
                                            <i class="<?php echo $icone; ?>"></i>
                                            <span><?php echo substr($icone, 8); ?></span>
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
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="ativo" class="form-check-input" id="ativo_novo" checked>
                            <label class="form-check-label" for="ativo_novo">Ativo (disponível para uso)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Tipo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Tipo -->
    <div class="modal fade" id="modalEditarTipo" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Tipo de Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome <span class="text-danger">*</span></label>
                                <input type="text" name="nome" id="edit_nome" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ordem de Exibição</label>
                                <input type="number" name="ordem" id="edit_ordem" class="form-control" value="0">
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
                                            <i class="<?php echo $icone; ?>"></i>
                                            <span><?php echo substr($icone, 8); ?></span>
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
                                        <option value="<?php echo $cor; ?>"><?php echo $nome; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="ativo" class="form-check-input" id="edit_ativo">
                            <label class="form-check-label" for="edit_ativo">Ativo (disponível para uso)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Atualizar</button>
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
                        <p>Tem certeza que deseja excluir o tipo de pagamento <strong id="delete_nome"></strong>?</p>
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
        // Sincronizar input de cor
        document.getElementById('cor_novo')?.addEventListener('change', function() {
            document.getElementById('cor_texto_novo').value = this.value;
        });
        
        function editarTipo(tipo) {
            document.getElementById('edit_id').value = tipo.id;
            document.getElementById('edit_nome').value = tipo.nome;
            document.getElementById('edit_descricao').value = tipo.descricao || '';
            document.getElementById('edit_icone').value = tipo.icone;
            document.getElementById('edit_cor').value = tipo.cor;
            document.getElementById('edit_cor_texto').value = tipo.cor;
            document.getElementById('edit_ordem').value = tipo.ordem;
            document.getElementById('edit_ativo').checked = tipo.ativo == 1;
            
            new bootstrap.Modal(document.getElementById('modalEditarTipo')).show();
        }
        
        function excluirTipo(id, nome) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_nome').innerText = nome;
            new bootstrap.Modal(document.getElementById('modalExcluir')).show();
        }
        
        // Atualizar cor do preview no modal de edição
        document.getElementById('edit_cor')?.addEventListener('change', function() {
            document.getElementById('edit_cor_texto').value = this.value;
        });
    </script>
    <style>
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
    </style>
</body>
</html>