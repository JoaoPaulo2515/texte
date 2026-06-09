<?php
// super-admin/comunicacao/enviar.php - Enviar notificações e comunicados
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar escolas para envio específico
$escolas = $conn->query("SELECT id, nome, subdominio, email FROM escolas WHERE status = 'ativa' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = $_POST['tipo'] ?? 'geral';
    $titulo = $_POST['titulo'] ?? '';
    $mensagem = $_POST['mensagem'] ?? '';
    $prioridade = $_POST['prioridade'] ?? 'normal';
    $escola_id = $_POST['escola_id'] ?? '';
    $enviar_email = isset($_POST['enviar_email']);
    $enviar_notificacao = isset($_POST['enviar_notificacao']);
    
    if (empty($titulo) || empty($mensagem)) {
        $error = "Preencha o título e a mensagem.";
    } else {
        try {
            $conn->beginTransaction();
            
            if ($tipo == 'geral') {
                // Enviar para todas as escolas
                $destinatarios = $escolas;
            } else {
                // Enviar para escola específica
                $stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :id");
                $stmt->execute([':id' => $escola_id]);
                $destinatarios = [$stmt->fetch(PDO::FETCH_ASSOC)];
            }
            
            $enviados = 0;
            
            foreach ($destinatarios as $escola) {
                if ($enviar_notificacao && $escola) {
                    // Criar notificação no sistema
                    $stmt = $conn->prepare("
                        INSERT INTO notificacoes (
                            escola_id, titulo, mensagem, tipo, prioridade, lida, created_at
                        ) VALUES (
                            :escola_id, :titulo, :mensagem, :tipo, :prioridade, 0, NOW()
                        )
                    ");
                    $stmt->execute([
                        ':escola_id' => $escola['id'],
                        ':titulo' => $titulo,
                        ':mensagem' => $mensagem,
                        ':tipo' => $prioridade == 'urgente' ? 'aviso' : 'info',
                        ':prioridade' => $prioridade
                    ]);
                    $enviados++;
                }
                
                if ($enviar_email && $escola && !empty($escola['email'])) {
                    // Enviar e-mail (implementar com PHPMailer)
                    $assunto = "[SIGE Angola] " . $titulo;
                    $corpo = "
                        <h2>{$titulo}</h2>
                        <p>{$mensagem}</p>
                        <hr>
                        <small>Mensagem enviada pelo administrador do SIGE Angola</small>
                    ";
                    // sendEmail($escola['email'], $assunto, $corpo);
                }
            }
            
            $conn->commit();
            
            $success = "Comunicado enviado com sucesso! {$enviados} notificações enviadas.";
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, dados_depois, ip, created_at)
                VALUES (:usuario_id, 'enviar_comunicado', 'notificacoes', :dados, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':dados' => json_encode(['titulo' => $titulo, 'tipo' => $tipo, 'prioridade' => $prioridade]),
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enviar Comunicado | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
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
        
        /* Navegação */
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
        
        /* Submenu */
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
        
        /* Main Content */
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
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 15px 20px;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
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
        
        .preview-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .required:after {
            content: "*";
            color: red;
            margin-left: 5px;
        }
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
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-headset"></i> Comunicação</a></li>
            
            <!-- Relatórios com Submenu -->
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="../relatorios/escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="../relatorios/estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="../relatorios/financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
                </ul>
            </li>
            
            <!-- Configurações com Submenu -->
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
            <h2><i class="fas fa-envelope"></i> Enviar Comunicado</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-paper-plane"></i> Novo Comunicado</h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST" id="formComunicado">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Tipo de Envio</label>
                                <select name="tipo" class="form-control" id="tipoEnvio">
                                    <option value="geral">Comunicado Geral (Todas as Escolas)</option>
                                    <option value="especifica">Comunicado Específico (Uma Escola)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Prioridade</label>
                                <select name="prioridade" class="form-control">
                                    <option value="normal">Normal</option>
                                    <option value="importante">Importante</option>
                                    <option value="urgente">Urgente</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row" id="divEscola" style="display: none;">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label>Escola</label>
                                <select name="escola_id" class="form-control">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($escolas as $e): ?>
                                    <option value="<?php echo $e['id']; ?>">
                                        <?php echo htmlspecialchars($e['nome']); ?> (<?php echo $e['subdominio']; ?>.sige.ao)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="required">Título do Comunicado</label>
                        <input type="text" name="titulo" class="form-control" id="titulo" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="required">Mensagem</label>
                        <textarea name="mensagem" class="form-control" rows="6" id="mensagem" required></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" name="enviar_notificacao" class="form-check-input" id="notificacao" checked>
                                    <label class="form-check-label">Enviar notificação no sistema</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" name="enviar_email" class="form-check-input" id="email">
                                    <label class="form-check-label">Enviar também por e-mail</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="preview-box">
                        <h6><i class="fas fa-eye"></i> Pré-visualização</h6>
                        <div id="preview">
                            <strong id="previewTitulo">Título do comunicado</strong>
                            <p id="previewMensagem">Mensagem...</p>
                            <small class="text-muted">Enviado pelo Administrador do SIGE Angola</small>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-paper-plane"></i> Enviar Comunicado
                        </button>
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
        
        $('#tipoEnvio').change(function() {
            if ($(this).val() == 'especifica') {
                $('#divEscola').show();
            } else {
                $('#divEscola').hide();
            }
        });
        
        $('#titulo, #mensagem').on('keyup change', function() {
            $('#previewTitulo').text($('#titulo').val() || 'Título do comunicado');
            $('#previewMensagem').text($('#mensagem').val() || 'Mensagem...');
        });
        
        $('#prioridade').change(function() {
            let prioridade = $(this).val();
            let preview = $('#preview');
            preview.removeClass('border border-danger border-warning');
            if (prioridade == 'urgente') {
                preview.addClass('border border-danger');
                $('#previewTitulo').css('color', '#dc3545');
            } else if (prioridade == 'importante') {
                preview.addClass('border border-warning');
                $('#previewTitulo').css('color', '#fd7e14');
            } else {
                $('#previewTitulo').css('color', '#006B3E');
            }
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) {
            $('#menuRelatorios').addClass('open');
            $('#submenuRelatorios').addClass('show');
        }
        if (currentPage.includes('config')) {
            $('#menuConfiguracoes').addClass('open');
            $('#submenuConfiguracoes').addClass('show');
        }
    </script>
</body>
</html>