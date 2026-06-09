<?php
// escola/config/pagamento/index.php - Formas de Pagamento
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

// Verificar se a tabela escola_formas_pagamento existe
$check = $conn->query("SHOW TABLES LIKE 'escola_formas_pagamento'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_formas_pagamento (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            tipo ENUM('dinheiro', 'transferencia', 'cheque', 'multicaixa', 'deposito') DEFAULT 'dinheiro',
            taxa_juros DECIMAL(5,2) DEFAULT 0,
            taxa_multa DECIMAL(5,2) DEFAULT 0,
            parcelas_maximo INT DEFAULT 1,
            instrucoes TEXT,
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Verificar se a tabela escola_pagamentos existe
$check = $conn->query("SHOW TABLES LIKE 'escola_pagamentos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_pagamentos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            aluno_id INT NOT NULL,
            forma_pagamento_id INT NOT NULL,
            valor DECIMAL(15,2) NOT NULL,
            valor_pago DECIMAL(15,2) NOT NULL,
            desconto DECIMAL(15,2) DEFAULT 0,
            multa DECIMAL(15,2) DEFAULT 0,
            referencia VARCHAR(100),
            descricao TEXT,
            data_vencimento DATE,
            data_pagamento DATE,
            status ENUM('pendente', 'pago', 'parcial', 'vencido', 'cancelado') DEFAULT 'pendente',
            comprovativo VARCHAR(255),
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (aluno_id) REFERENCES estudantes(id) ON DELETE CASCADE,
            FOREIGN KEY (forma_pagamento_id) REFERENCES escola_formas_pagamento(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    // Adicionar forma de pagamento
    if ($acao == 'add_forma') {
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $tipo = $_POST['tipo'];
        $taxa_juros = str_replace(',', '.', $_POST['taxa_juros']);
        $taxa_multa = str_replace(',', '.', $_POST['taxa_multa']);
        $parcelas_maximo = $_POST['parcelas_maximo'];
        $instrucoes = $_POST['instrucoes'];
        
        $stmt = $conn->prepare("
            INSERT INTO escola_formas_pagamento 
            (escola_id, nome, descricao, tipo, taxa_juros, taxa_multa, parcelas_maximo, instrucoes, status)
            VALUES (:escola_id, :nome, :descricao, :tipo, :taxa_juros, :taxa_multa, :parcelas_maximo, :instrucoes, 'ativo')
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':taxa_juros' => $taxa_juros,
            ':taxa_multa' => $taxa_multa,
            ':parcelas_maximo' => $parcelas_maximo,
            ':instrucoes' => $instrucoes
        ]);
        
        $_SESSION['mensagem'] = "Forma de pagamento adicionada com sucesso!";
        header("Location: index.php");
        exit;
    }
    
    // Editar forma de pagamento
    if ($acao == 'edit_forma') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $tipo = $_POST['tipo'];
        $taxa_juros = str_replace(',', '.', $_POST['taxa_juros']);
        $taxa_multa = str_replace(',', '.', $_POST['taxa_multa']);
        $parcelas_maximo = $_POST['parcelas_maximo'];
        $instrucoes = $_POST['instrucoes'];
        
        $stmt = $conn->prepare("
            UPDATE escola_formas_pagamento 
            SET nome = :nome, descricao = :descricao, tipo = :tipo, 
                taxa_juros = :taxa_juros, taxa_multa = :taxa_multa, 
                parcelas_maximo = :parcelas_maximo, instrucoes = :instrucoes
            WHERE id = :id AND escola_id = :escola_id
        ");
        $stmt->execute([
            ':id' => $id,
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':taxa_juros' => $taxa_juros,
            ':taxa_multa' => $taxa_multa,
            ':parcelas_maximo' => $parcelas_maximo,
            ':instrucoes' => $instrucoes
        ]);
        
        $_SESSION['mensagem'] = "Forma de pagamento atualizada!";
        header("Location: index.php");
        exit;
    }
    
    // Ativar/Desativar forma
    if ($acao == 'toggle_forma') {
        $id = $_POST['id'];
        $status = $_POST['status'];
        $stmt = $conn->prepare("UPDATE escola_formas_pagamento SET status = :status WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id, ':status' => $status]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Excluir forma
    if ($acao == 'delete_forma') {
        $id = $_POST['id'];
        $stmt = $conn->prepare("DELETE FROM escola_formas_pagamento WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Registrar pagamento
    if ($acao == 'add_pagamento') {
        $aluno_id = $_POST['aluno_id'];
        $forma_pagamento_id = $_POST['forma_pagamento_id'];
        $valor = str_replace(',', '', $_POST['valor']);
        $desconto = str_replace(',', '', $_POST['desconto'] ?? 0);
        $multa = str_replace(',', '', $_POST['multa'] ?? 0);
        $referencia = $_POST['referencia'];
        $descricao = $_POST['descricao'];
        $data_pagamento = $_POST['data_pagamento'];
        
        $valor_pago = $valor - $desconto + $multa;
        
        // Upload do comprovativo
        $comprovativo = null;
        if (isset($_FILES['comprovativo']) && $_FILES['comprovativo']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['comprovativo']['name'], PATHINFO_EXTENSION));
            $comprovativo = 'pagamento_' . time() . '_' . uniqid() . '.' . $ext;
            $upload_dir = __DIR__ . '/../../../uploads/pagamentos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            move_uploaded_file($_FILES['comprovativo']['tmp_name'], $upload_dir . $comprovativo);
        }
        
        $stmt = $conn->prepare("
            INSERT INTO escola_pagamentos 
            (escola_id, aluno_id, forma_pagamento_id, valor, valor_pago, desconto, multa, referencia, descricao, data_pagamento, comprovativo, status, usuario_id)
            VALUES (:escola_id, :aluno_id, :forma_pagamento_id, :valor, :valor_pago, :desconto, :multa, :referencia, :descricao, :data_pagamento, :comprovativo, 'pago', :usuario_id)
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':aluno_id' => $aluno_id,
            ':forma_pagamento_id' => $forma_pagamento_id,
            ':valor' => $valor,
            ':valor_pago' => $valor_pago,
            ':desconto' => $desconto,
            ':multa' => $multa,
            ':referencia' => $referencia,
            ':descricao' => $descricao,
            ':data_pagamento' => $data_pagamento,
            ':comprovativo' => $comprovativo,
            ':usuario_id' => $usuario_id
        ]);
        
        $_SESSION['mensagem'] = "Pagamento registado com sucesso!";
        header("Location: index.php");
        exit;
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar formas de pagamento
$formas = $conn->prepare("SELECT * FROM escola_formas_pagamento WHERE escola_id = :escola_id ORDER BY status DESC, nome ASC");
$formas->execute([':escola_id' => $escola_id]);
$formas = $formas->fetchAll(PDO::FETCH_ASSOC);

// Buscar pagamentos recentes
$pagamentos = $conn->prepare("
    SELECT p.*, u.nome as aluno_nome, f.nome as forma_nome, f.tipo as forma_tipo
    FROM escola_pagamentos p
    JOIN estudantes e ON e.id = p.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    JOIN escola_formas_pagamento f ON f.id = p.forma_pagamento_id
    WHERE p.escola_id = :escola_id 
    ORDER BY p.created_at DESC 
    LIMIT 30
");
$pagamentos->execute([':escola_id' => $escola_id]);
$pagamentos = $pagamentos->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos para o select
$alunos = $conn->prepare("
    SELECT e.id, u.nome, e.matricula 
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.escola_id = :escola_id 
    ORDER BY u.nome ASC
");
$alunos->execute([':escola_id' => $escola_id]);
$alunos = $alunos->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formas de Pagamento | SIGE Angola</title>
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
        
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .badge-pago { background: #d4edda; color: #155724; }
        .badge-pendente { background: #fff3cd; color: #856404; }
        .badge-vencido { background: #f8d7da; color: #721c24; }
        .table-responsive { overflow-x: auto; }
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
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="../sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-credit-card"></i> Formas de Pagamento</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaForma">
                <i class="fas fa-plus"></i> Nova Forma
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Formas de Pagamento -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Formas de Pagamento Disponíveis</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th>Nome</th><th>Tipo</th><th>Taxa Juros</th><th>Taxa Multa</th><th>Parcelas</th><th>Status</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($formas as $forma): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($forma['nome']); ?></strong><br><small><?php echo htmlspecialchars($forma['descricao']); ?></small></div>
                                <td>
                                    <?php
                                    $tipos = ['dinheiro' => 'Dinheiro', 'transferencia' => 'Transferência', 'cheque' => 'Cheque', 'multicaixa' => 'Multicaixa', 'deposito' => 'Depósito'];
                                    echo $tipos[$forma['tipo']] ?? $forma['tipo'];
                                    ?>
                                 </div>
                                <td><?php echo $forma['taxa_juros']; ?>%</div>
                                <td><?php echo $forma['taxa_multa']; ?>%</div>
                                <td><?php echo $forma['parcelas_maximo']; ?>x</div>
                                <td><span class="badge <?php echo $forma['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $forma['status']; ?></span></div>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editarForma(<?php echo $forma['id']; ?>, '<?php echo htmlspecialchars($forma['nome']); ?>', '<?php echo addslashes($forma['descricao']); ?>', '<?php echo $forma['tipo']; ?>', '<?php echo $forma['taxa_juros']; ?>', '<?php echo $forma['taxa_multa']; ?>', '<?php echo $forma['parcelas_maximo']; ?>', '<?php echo addslashes($forma['instrucoes']); ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" onclick="toggleForma(<?php echo $forma['id']; ?>, '<?php echo $forma['status']; ?>')">
                                        <i class="fas fa-toggle-<?php echo $forma['status'] == 'ativo' ? 'off' : 'on'; ?>"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="excluirForma(<?php echo $forma['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
        
        <!-- Registrar Pagamento -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-money-bill"></i> Registrar Pagamento</span>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoPagamento">
                    <i class="fas fa-plus"></i> Novo Pagamento
                </button>
            </div>
        </div>
        
        <!-- Últimos Pagamentos -->
        <div class="card">
            <div class="card-header"><i class="fas fa-history"></i> Últimos Pagamentos</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th>Data</th><th>Aluno</th><th>Forma</th><th>Valor</th><th>Desconto</th><th>Multa</th><th>Total Pago</th><th>Status</th><th>Comprovativo</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagamentos as $pag): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($pag['data_pagamento'])); ?></td>
                                <td><?php echo htmlspecialchars($pag['aluno_nome']); ?><br><small><?php echo $pag['matricula']; ?></small></div>
                                <td><?php echo htmlspecialchars($pag['forma_nome']); ?><br><small><?php echo $tipos[$pag['forma_tipo']] ?? $pag['forma_tipo']; ?></small></div>
                                <td><?php echo number_format($pag['valor'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($pag['desconto'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($pag['multa'], 2, ',', '.'); ?> Kz</div>
                                <td><strong><?php echo number_format($pag['valor_pago'], 2, ',', '.'); ?> Kz</strong></div>
                                <td><span class="badge badge-<?php echo $pag['status']; ?>"><?php echo ucfirst($pag['status']); ?></span></div>
                                <td>
                                    <?php if ($pag['comprovativo']): ?>
                                        <a href="../../../uploads/pagamentos/<?php echo $pag['comprovativo']; ?>" target="_blank" class="btn btn-sm btn-secondary"><i class="fas fa-file-pdf"></i></a>
                                    <?php else: ?>
                                        --
                                    <?php endif; ?>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modais -->
    <div class="modal fade" id="modalNovaForma" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Nova Forma de Pagamento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_forma"><div class="modal-body"><div class="mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" required></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div><div class="mb-3"><label>Tipo</label><select name="tipo" class="form-control"><option value="dinheiro">Dinheiro</option><option value="transferencia">Transferência Bancária</option><option value="cheque">Cheque</option><option value="multicaixa">Multicaixa</option><option value="deposito">Depósito</option></select></div><div class="row"><div class="col-md-6 mb-3"><label>Taxa de Juros (%)</label><input type="number" step="0.01" name="taxa_juros" class="form-control" value="0"></div><div class="col-md-6 mb-3"><label>Taxa de Multa (%)</label><input type="number" step="0.01" name="taxa_multa" class="form-control" value="0"></div></div><div class="mb-3"><label>Nº Máximo de Parcelas</label><input type="number" name="parcelas_maximo" class="form-control" value="1" min="1"></div><div class="mb-3"><label>Instruções de Pagamento</label><textarea name="instrucoes" class="form-control" rows="3" placeholder="Ex: NIB, IBAN, SWIFT, etc."></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <div class="modal fade" id="modalEditarForma" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Forma de Pagamento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_forma"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="mb-3"><label>Nome</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" id="edit_descricao" class="form-control" rows="2"></textarea></div><div class="mb-3"><label>Tipo</label><select name="tipo" id="edit_tipo" class="form-control"><option value="dinheiro">Dinheiro</option><option value="transferencia">Transferência Bancária</option><option value="cheque">Cheque</option><option value="multicaixa">Multicaixa</option><option value="deposito">Depósito</option></select></div><div class="row"><div class="col-md-6 mb-3"><label>Taxa de Juros (%)</label><input type="number" step="0.01" name="taxa_juros" id="edit_taxa_juros" class="form-control"></div><div class="col-md-6 mb-3"><label>Taxa de Multa (%)</label><input type="number" step="0.01" name="taxa_multa" id="edit_taxa_multa" class="form-control"></div></div><div class="mb-3"><label>Nº Máximo de Parcelas</label><input type="number" name="parcelas_maximo" id="edit_parcelas_maximo" class="form-control" min="1"></div><div class="mb-3"><label>Instruções de Pagamento</label><textarea name="instrucoes" id="edit_instrucoes" class="form-control" rows="3"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <div class="modal fade" id="modalNovoPagamento" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-money-bill"></i> Registrar Pagamento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="acao" value="add_pagamento"><div class="modal-body"><div class="row"><div class="col-md-6 mb-3"><label>Aluno</label><select name="aluno_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($alunos as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option><?php endforeach; ?></select></div><div class="col-md-6 mb-3"><label>Forma de Pagamento</label><select name="forma_pagamento_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($formas as $f): if($f['status']=='ativo'){ ?><option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars($f['nome']); ?></option><?php } endforeach; ?></select></div><div class="col-md-4 mb-3"><label>Valor (Kz)</label><input type="number" step="0.01" name="valor" class="form-control" required></div><div class="col-md-4 mb-3"><label>Desconto (Kz)</label><input type="number" step="0.01" name="desconto" class="form-control" value="0"></div><div class="col-md-4 mb-3"><label>Multa (Kz)</label><input type="number" step="0.01" name="multa" class="form-control" value="0"></div><div class="col-md-6 mb-3"><label>Referência</label><input type="text" name="referencia" class="form-control" placeholder="Nº de referência/recibo"></div><div class="col-md-6 mb-3"><label>Data do Pagamento</label><input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div><div class="col-md-12 mb-3"><label>Descrição</label><textarea name="descricao" class="form-control" rows="2" placeholder="Motivo do pagamento (ex: Mensalidade, Matrícula, etc.)"></textarea></div><div class="col-md-12 mb-3"><label>Comprovativo</label><input type="file" name="comprovativo" class="form-control" accept=".jpg,.jpeg,.png,.pdf"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Registrar Pagamento</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        
        function editarForma(id, nome, desc, tipo, juros, multa, parcelas, instrucoes) {
            $('#edit_id').val(id); $('#edit_nome').val(nome); $('#edit_descricao').val(desc); $('#edit_tipo').val(tipo);
            $('#edit_taxa_juros').val(juros); $('#edit_taxa_multa').val(multa); $('#edit_parcelas_maximo').val(parcelas); $('#edit_instrucoes').val(instrucoes);
            $('#modalEditarForma').modal('show');
        }
        
        function toggleForma(id, status) { var novoStatus = (status == 'ativo' ? 'inativo' : 'ativo'); $.post('index.php', { acao: 'toggle_forma', id: id, status: novoStatus }, function(r) { location.reload(); }); }
        function excluirForma(id) { if(confirm('Tem certeza que deseja excluir esta forma de pagamento?')) $.post('index.php', { acao: 'delete_forma', id: id }, function(r) { location.reload(); }); }
    </script>
</body>
</html>