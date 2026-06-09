<?php
// super-admin/escolas/index.php - Listagem de Escolas
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Filtros
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$provincia_filter = $_GET['provincia'] ?? '';

$query = "
    SELECT e.*, p.nome as plano_nome, 
           (SELECT COUNT(*) FROM usuarios WHERE escola_id = e.id) as total_usuarios,
           (SELECT id FROM usuarios WHERE escola_id = e.id AND tipo = 'admin_escola' LIMIT 1) as admin_id,
           (SELECT nome FROM usuarios WHERE escola_id = e.id AND tipo = 'admin_escola' LIMIT 1) as admin_nome,
           (SELECT email FROM usuarios WHERE escola_id = e.id AND tipo = 'admin_escola' LIMIT 1) as admin_email
    FROM escolas e
    LEFT JOIN planos p ON p.id = e.plano_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $query .= " AND (e.nome LIKE :search OR e.subdominio LIKE :search OR e.email LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($status_filter) {
    $query .= " AND e.status = :status";
    $params[':status'] = $status_filter;
}
if ($provincia_filter) {
    $query .= " AND e.provincia = :provincia";
    $params[':provincia'] = $provincia_filter;
}

$query .= " ORDER BY e.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Províncias para filtro
$provincias = $conn->query("SELECT DISTINCT provincia FROM escolas WHERE provincia IS NOT NULL ORDER BY provincia")->fetchAll(PDO::FETCH_COLUMN);

// Processar alteração de dados do admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'alterar_acesso_admin') {
    $escola_id = $_POST['escola_id'] ?? 0;
    $admin_id = $_POST['admin_id'] ?? 0;
    $novo_email = trim($_POST['novo_email'] ?? '');
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        // Validar e-mail
        if (empty($novo_email)) {
            throw new Exception('O e-mail é obrigatório');
        }
        if (!filter_var($novo_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Digite um e-mail válido');
        }
        
        // Verificar se e-mail já existe em outro usuário
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = :email AND id != :admin_id");
        $stmt->execute([':email' => $novo_email, ':admin_id' => $admin_id]);
        if ($stmt->fetch()) {
            throw new Exception('Este e-mail já está em uso por outro usuário');
        }
        
        // Preparar atualização
        $update_sql = "UPDATE usuarios SET email = :email, updated_at = NOW()";
        $params = [':email' => $novo_email, ':admin_id' => $admin_id];
        
        // Se senha foi fornecida, alterar
        if (!empty($nova_senha)) {
            if (strlen($nova_senha) < 6) {
                throw new Exception('A senha deve ter no mínimo 6 caracteres');
            }
            if ($nova_senha !== $confirmar_senha) {
                throw new Exception('As senhas não coincidem');
            }
            $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $update_sql .= ", senha = :senha";
            $params[':senha'] = $nova_senha_hash;
        }
        
        $update_sql .= " WHERE id = :admin_id";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->execute($params);
        
        // Log da ação
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, dados_depois, ip, created_at)
            VALUES (:usuario_id, 'alterar_acesso_admin', 'usuarios', :registro_id, :dados, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $admin_id,
            ':dados' => json_encode(['email' => $novo_email, 'senha_alterada' => !empty($nova_senha)]),
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        $response['success'] = true;
        $response['message'] = 'Dados de acesso alterados com sucesso!';
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escolas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        .nav-submenu .nav-link { padding: 8px 25px; font-size: 0.9em; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75em; font-weight: 500; }
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-suspensa { background: #fff3e0; color: #f57c00; }
        .status-trial { background: #e3f2fd; color: #1976d2; }
        .status-inativa { background: #ffebee; color: #d32f2f; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .logo-preview { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }
        
        /* Modal de Confirmação */
        .modal-alterar .modal-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-bottom: none;
        }
        .modal-alterar .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .info-text {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }
        .password-strength { margin-top: 5px; font-size: 0.8em; }
        .strength-weak { color: #dc2626; }
        .strength-medium { color: #f59e0b; }
        .strength-strong { color: #10b981; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
            <div class="user-info-sidebar">
                <small><i class="fas fa-user-shield"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Super Admin'; ?></small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="../relatorios/escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="../relatorios/estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="../relatorios/financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
                </ul>
            </li>
            
            <li class="nav-item has-submenu" id="menuConfiguracoes">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-cog"></i> Configurações</a>
                <ul class="nav-submenu" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../config/sistema.php" class="nav-link"><i class="fas fa-globe"></i> Configurações do Sistema</a></li>
                    <li class="nav-item"><a href="../config/permissoes.php" class="nav-link"><i class="fas fa-lock"></i> Permissões e Papéis</a></li>
                </ul>
            </li>
            
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-school"></i> Escolas</h2>
            <a href="cadastrar.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nova Escola</a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Buscar escola..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-control">
                            <option value="">Todos os status</option>
                            <option value="ativa" <?php echo $status_filter == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                            <option value="trial" <?php echo $status_filter == 'trial' ? 'selected' : ''; ?>>Trial</option>
                            <option value="suspensa" <?php echo $status_filter == 'suspensa' ? 'selected' : ''; ?>>Suspensa</option>
                            <option value="inativa" <?php echo $status_filter == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="provincia" class="form-control">
                            <option value="">Todas as províncias</option>
                            <?php foreach ($provincias as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo $provincia_filter == $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Logo</th>
                                <th>Escola</th>
                                <th>Subdomínio</th>
                                <th>Província</th>
                                <th>Plano</th>
                                <th>Status</th>
                                <th>Admin</th>
                                <th>Usuários</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($escolas as $escola): ?>
                            <tr>
                                <td>
                                    <?php if ($escola['logo']): ?>
                                        <img src="../../uploads/escolas/thumb_<?php echo $escola['logo']; ?>" class="logo-preview">
                                    <?php else: ?>
                                        <i class="fas fa-school fa-2x text-muted"></i>
                                    <?php endif; ?>
                                 </div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($escola['nome']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($escola['email']); ?></small>
                                 </div>
                                </td>
                                <td><?php echo $escola['subdominio']; ?>.sige.ao</small></td>
                                <td><?php echo $escola['provincia'] ?? '-'; ?></td>
                                <td><?php echo $escola['plano_nome'] ?? '-'; ?></td>
                                <td><span class="status-badge status-<?php echo $escola['status']; ?>"><?php echo ucfirst($escola['status']); ?></span></td>
                                <td>
                                    <?php if ($escola['admin_nome']): ?>
                                        <strong><?php echo htmlspecialchars($escola['admin_nome']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($escola['admin_email']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Não definido</span>
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td><?php echo $escola['total_usuarios']; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="visualizar.php?id=<?php echo $escola['id']; ?>" class="btn btn-info" title="Visualizar"><i class="fas fa-eye"></i></a>
                                        <a href="editar.php?id=<?php echo $escola['id']; ?>" class="btn btn-warning" title="Editar"><i class="fas fa-edit"></i></a>
                                        <button type="button" class="btn btn-secondary" title="Alterar Acesso Admin" 
                                                data-bs-toggle="modal" data-bs-target="#modalAlterarAcesso"
                                                data-escola-id="<?php echo $escola['id']; ?>"
                                                data-escola-nome="<?php echo htmlspecialchars($escola['nome']); ?>"
                                                data-admin-id="<?php echo $escola['admin_id']; ?>"
                                                data-admin-nome="<?php echo htmlspecialchars($escola['admin_nome']); ?>"
                                                data-admin-email="<?php echo htmlspecialchars($escola['admin_email']); ?>">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <a href="excluir.php?id=<?php echo $escola['id']; ?>" class="btn btn-danger" title="Excluir" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                    </div>
                                 </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($escolas)): ?>
                            <tr>
                                <td colspan="9" class="text-center">Nenhuma escola encontrada</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Alterar Acesso Admin -->
    <div class="modal fade modal-alterar" id="modalAlterarAcesso" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key"></i> Alterar Dados de Acesso do Administrador
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="info-text" id="infoEscolaAdmin">
                        <p><strong>🏫 Escola:</strong> <span id="infoEscolaNome"></span></p>
                        <p><strong>👤 Administrador atual:</strong> <span id="infoAdminNome"></span></p>
                        <p><strong>📧 E-mail atual:</strong> <span id="infoAdminEmail"></span></p>
                    </div>
                    
                    <form id="formAlterarAcesso">
                        <input type="hidden" name="escola_id" id="escola_id">
                        <input type="hidden" name="admin_id" id="admin_id">
                        <input type="hidden" name="acao" value="alterar_acesso_admin">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Novo E-mail</label>
                            <input type="email" name="novo_email" id="novo_email" class="form-control" required>
                            <small class="text-muted">O e-mail será usado para login</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nova Senha <span class="text-muted">(opcional)</span></label>
                            <input type="password" name="nova_senha" id="nova_senha" class="form-control" placeholder="Deixe em branco para manter a atual">
                            <div class="password-strength" id="passwordStrength"></div>
                            <small class="text-muted">Mínimo 6 caracteres. Deixe em branco para não alterar.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirmar Nova Senha</label>
                            <input type="password" name="confirmar_senha" id="confirmar_senha" class="form-control" disabled>
                            <div id="confirmError" class="text-danger small mt-1"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-primary" id="btnConfirmarAlteracao">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade" id="modalConfirmacao" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title text-dark">
                        <i class="fas fa-exclamation-triangle"></i> Confirmar Alteração
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-shield-alt fa-4x text-warning"></i>
                    </div>
                    <p class="text-center">
                        <strong>Tem certeza que deseja alterar os dados de acesso do administrador?</strong>
                    </p>
                    <div class="alert alert-info">
                        <strong>📋 Resumo das alterações:</strong>
                        <ul class="mb-0 mt-2" id="resumoAlteracoes">
                            <li>Novo e-mail: <span id="resumoEmail"></span></li>
                            <li>Alterar senha: <span id="resumoSenha">Não</span></li>
                        </ul>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i>
                        <strong>Importante:</strong> Após a alteração, o administrador precisará usar as novas credenciais para acessar o sistema.
                    </div>
                    <div class="form-check mt-3">
                        <input type="checkbox" class="form-check-input" id="confirmarCheckbox">
                        <label class="form-check-label" for="confirmarCheckbox">
                            Confirmo que desejo alterar os dados de acesso
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-warning" id="btnConfirmar" disabled>
                        <i class="fas fa-check"></i> Sim, Confirmar Alteração
                    </button>
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
        
        // Variáveis globais
        let escolaSelecionada = {};
        
        // Carregar dados no modal
        $('#modalAlterarAcesso').on('show.bs.modal', function(event) {
            const button = $(event.relatedTarget);
            escolaSelecionada = {
                id: button.data('escola-id'),
                nome: button.data('escola-nome'),
                adminId: button.data('admin-id'),
                adminNome: button.data('admin-nome'),
                adminEmail: button.data('admin-email')
            };
            
            $('#infoEscolaNome').text(escolaSelecionada.nome);
            $('#infoAdminNome').text(escolaSelecionada.adminNome || 'Não definido');
            $('#infoAdminEmail').text(escolaSelecionada.adminEmail || 'Não definido');
            $('#escola_id').val(escolaSelecionada.id);
            $('#admin_id').val(escolaSelecionada.adminId);
            $('#novo_email').val(escolaSelecionada.adminEmail || '');
            $('#nova_senha').val('');
            $('#confirmar_senha').val('');
            $('#confirmar_senha').prop('disabled', true);
            $('#passwordStrength').html('');
            $('#confirmError').html('');
        });
        
        // Habilitar/desabilitar confirmação de senha
        $('#nova_senha').on('input', function() {
            const hasPassword = $(this).val().length > 0;
            $('#confirmar_senha').prop('disabled', !hasPassword);
            
            if (!hasPassword) {
                $('#confirmar_senha').val('');
                $('#confirmError').html('');
                $('#passwordStrength').html('');
            } else {
                checkPasswordStrength($(this).val());
            }
            
            if (!hasPassword && $('#confirmar_senha').val()) {
                checkPasswordMatch();
            }
        });
        
        // Verificar força da senha
        function checkPasswordStrength(password) {
            let strength = 0;
            let message = '';
            let className = '';
            
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            if (strength <= 1) {
                message = '🔴 Senha fraca';
                className = 'strength-weak';
            } else if (strength <= 3) {
                message = '🟡 Senha média';
                className = 'strength-medium';
            } else {
                message = '🟢 Senha forte';
                className = 'strength-strong';
            }
            
            $('#passwordStrength').html(message);
            $('#passwordStrength').removeClass('strength-weak strength-medium strength-strong').addClass(className);
        }
        
        // Verificar coincidência de senha
        $('#confirmar_senha').on('input', function() {
            checkPasswordMatch();
        });
        
        function checkPasswordMatch() {
            const senha = $('#nova_senha').val();
            const confirm = $('#confirmar_senha').val();
            
            if (confirm.length > 0 && senha !== confirm) {
                $('#confirmError').html('<i class="fas fa-times-circle me-1"></i> As senhas não coincidem');
                return false;
            } else if (confirm.length > 0 && senha === confirm) {
                $('#confirmError').html('<i class="fas fa-check-circle me-1"></i> Senhas coincidem');
                $('#confirmError').css('color', '#10b981');
                return true;
            } else {
                $('#confirmError').html('');
                return true;
            }
        }
        
        // Abrir modal de confirmação
        $('#btnConfirmarAlteracao').click(function() {
            const novoEmail = $('#novo_email').val();
            const novaSenha = $('#nova_senha').val();
            
            if (!novoEmail) {
                alert('Digite o novo e-mail');
                $('#novo_email').focus();
                return;
            }
            
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(novoEmail)) {
                alert('Digite um e-mail válido');
                $('#novo_email').focus();
                return;
            }
            
            if (novaSenha.length > 0 && novaSenha.length < 6) {
                alert('A senha deve ter no mínimo 6 caracteres');
                $('#nova_senha').focus();
                return;
            }
            
            if (novaSenha.length > 0 && novaSenha !== $('#confirmar_senha').val()) {
                alert('As senhas não coincidem');
                $('#confirmar_senha').focus();
                return;
            }
            
            // Preencher resumo
            $('#resumoEmail').text(novoEmail);
            $('#resumoSenha').text(novaSenha.length > 0 ? 'Sim' : 'Não');
            
            // Fechar primeiro modal e abrir modal de confirmação
            $('#modalAlterarAcesso').modal('hide');
            $('#modalConfirmacao').modal('show');
        });
        
        // Habilitar botão de confirmação
        $('#confirmarCheckbox').change(function() {
            $('#btnConfirmar').prop('disabled', !$(this).is(':checked'));
        });
        
        // Processar alteração
        $('#btnConfirmar').click(function() {
            const btn = $(this);
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processando...');
            
            $.ajax({
                url: 'index.php',
                method: 'POST',
                data: $('#formAlterarAcesso').serialize(),
                dataType: 'json',
                success: function(response) {
                    $('#modalConfirmacao').modal('hide');
                    
                    if (response.success) {
                        alert('✅ ' + response.message);
                        location.reload();
                    } else {
                        alert('❌ ' + response.message);
                    }
                },
                error: function() {
                    $('#modalConfirmacao').modal('hide');
                    alert('❌ Erro ao processar solicitação. Tente novamente.');
                },
                complete: function() {
                    btn.prop('disabled', false).html('<i class="fas fa-check"></i> Sim, Confirmar Alteração');
                    $('#confirmarCheckbox').prop('checked', false);
                    $('#btnConfirmar').prop('disabled', true);
                }
            });
        });
        
        // Resetar modal de confirmação ao fechar
        $('#modalConfirmacao').on('hidden.bs.modal', function() {
            $('#confirmarCheckbox').prop('checked', false);
            $('#btnConfirmar').prop('disabled', true);
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatorios').addClass('show'); }
        if (currentPage.includes('config')) { $('#menuConfiguracoes').addClass('open'); $('#submenuConfiguracoes').addClass('show'); }
    </script>
</body>
</html>