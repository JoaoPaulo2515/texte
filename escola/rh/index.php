<?php
// escola/rh/index.php - Dashboard de Recursos Humanos
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================

// Verificar se a tabela funcionarios existe
$check = $conn->query("SHOW TABLES LIKE 'funcionarios'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS funcionarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT,
            escola_id INT NOT NULL,
            numero_processo VARCHAR(50) UNIQUE NOT NULL,
            nome VARCHAR(150) NOT NULL,
            tipo_funcionario ENUM('professor', 'administrativo', 'auxiliar', 'seguranca', 'limpeza', 'manutencao', 'motorista', 'outro') DEFAULT 'professor',
            cargo VARCHAR(100),
            bi VARCHAR(20) UNIQUE,
            bi_emissao DATE,
            bi_validade DATE,
            nuit VARCHAR(20),
            nacionalidade VARCHAR(50) DEFAULT 'Angolana',
            naturalidade VARCHAR(100),
            provincia_id INT,
            provincia_nome VARCHAR(100),
            municipio_id INT,
            municipio_nome VARCHAR(100),
            comuna_id INT,
            comuna_nome VARCHAR(100),
            endereco TEXT,
            data_nascimento DATE,
            genero ENUM('M', 'F'),
            estado_civil VARCHAR(30),
            nome_pai VARCHAR(150),
            nome_mae VARCHAR(150),
            telefone VARCHAR(20),
            telefone_emergencia VARCHAR(20),
            nome_emergencia VARCHAR(150),
            email VARCHAR(100),
            data_admissao DATE,
            tipo_contrato VARCHAR(50),
            data_fim_contrato DATE,
            habilitacao VARCHAR(100),
            formacao TEXT,
            banco VARCHAR(50),
            iban VARCHAR(50),
            foto VARCHAR(255),
            status ENUM('ativo', 'inativo', 'ferias', 'licenca') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            INDEX idx_bi (bi),
            INDEX idx_numero_processo (numero_processo),
            INDEX idx_status (status)
        )
    ");
}

// Verificar se a tabela funcionarios_documentos existe
$check = $conn->query("SHOW TABLES LIKE 'funcionarios_documentos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS funcionarios_documentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            funcionario_id INT NOT NULL,
            tipo_documento VARCHAR(50) NOT NULL,
            nome_arquivo VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(500) NOT NULL,
            formato_papel VARCHAR(10),
            tamanho_arquivo INT,
            data_upload TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE
        )
    ");
}

// Verificar se a tabela vagas_emprego existe
$check = $conn->query("SHOW TABLES LIKE 'vagas_emprego'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS vagas_emprego (
            id INT AUTO_INCREMENT PRIMARY KEY,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            requisitos TEXT,
            tipo_contrato VARCHAR(50),
            cargo VARCHAR(100),
            quantidade INT DEFAULT 1,
            data_abertura DATE,
            data_fecho DATE,
            status ENUM('aberta', 'fechada', 'cancelada') DEFAULT 'aberta',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Verificar se a tabela candidatos existe
$check = $conn->query("SHOW TABLES LIKE 'candidatos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS candidatos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vaga_id INT NOT NULL,
            nome VARCHAR(150) NOT NULL,
            email VARCHAR(100),
            telefone VARCHAR(20),
            bi VARCHAR(20),
            curriculo VARCHAR(500),
            status ENUM('pendente', 'analisado', 'entrevistado', 'aprovado', 'reprovado') DEFAULT 'pendente',
            data_candidatura TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vaga_id) REFERENCES vagas_emprego(id) ON DELETE CASCADE
        )
    ");
}

// Verificar se a tabela avaliacao_periodos existe
$check = $conn->query("SHOW TABLES LIKE 'avaliacao_periodos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS avaliacao_periodos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            data_inicio DATE NOT NULL,
            data_fim DATE NOT NULL,
            peso DECIMAL(5,2) DEFAULT 1.00,
            status ENUM('ativa', 'encerrada', 'pendente') DEFAULT 'pendente',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Verificar se a tabela avaliacoes existe
$check = $conn->query("SHOW TABLES LIKE 'avaliacoes'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS avaliacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            funcionario_id INT NOT NULL,
            periodo_id INT NOT NULL,
            pontuacao_total DECIMAL(5,2),
            classificacao ENUM('Excelente', 'Bom', 'Regular', 'Insatisfatório'),
            data_avaliacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE,
            FOREIGN KEY (periodo_id) REFERENCES avaliacao_periodos(id) ON DELETE CASCADE
        )
    ");
}

// Verificar se a tabela planos_formacao existe
$check = $conn->query("SHOW TABLES LIKE 'planos_formacao'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS planos_formacao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            data_inicio DATE,
            data_fim DATE,
            carga_horaria INT,
            status ENUM('planejado', 'em_andamento', 'concluido', 'cancelado') DEFAULT 'planejado',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// ============================================
// ESTATÍSTICAS DO DASHBOARD
// ============================================

// Total de funcionários
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ?");
$stmt->execute([$escola_id]);
$stats['total_funcionarios'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ? AND tipo_funcionario = 'professor'");
$stmt->execute([$escola_id]);
$stats['total_professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de administrativos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ? AND tipo_funcionario = 'administrativo'");
$stmt->execute([$escola_id]);
$stats['total_administrativos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de funcionários ativos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ? AND status = 'ativo'");
$stmt->execute([$escola_id]);
$stats['funcionarios_ativos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Funcionários admitidos no mês
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ? AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
$stmt->execute([$escola_id]);
$stats['admitidos_mes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Vagas abertas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM vagas_emprego WHERE escola_id = ? AND status = 'aberta'");
$stmt->execute([$escola_id]);
$stats['vagas_abertas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Candidatos pendentes
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM candidatos c JOIN vagas_emprego v ON c.vaga_id = v.id WHERE v.escola_id = ? AND c.status = 'pendente'");
$stmt->execute([$escola_id]);
$stats['candidatos_pendentes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Avaliações pendentes
$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM funcionarios f 
    LEFT JOIN avaliacoes a ON a.funcionario_id = f.id AND a.periodo_id = (SELECT id FROM avaliacao_periodos WHERE escola_id = ? AND status = 'ativa' LIMIT 1)
    WHERE f.escola_id = ? AND f.status = 'ativo' AND a.id IS NULL
");
$stmt->execute([$escola_id, $escola_id]);
$stats['avaliacoes_pendentes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Aniversariantes do mês
$stmt = $conn->prepare("
    SELECT id, nome, cargo, data_nascimento, foto 
    FROM funcionarios 
    WHERE escola_id = ? 
    AND data_nascimento IS NOT NULL 
    AND MONTH(data_nascimento) = MONTH(NOW())
    ORDER BY DAY(data_nascimento)
    LIMIT 10
");
$stmt->execute([$escola_id]);
$aniversariantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contratos a expirar (próximos 30 dias)
$stmt = $conn->prepare("
    SELECT id, nome, cargo, data_fim_contrato 
    FROM funcionarios 
    WHERE escola_id = ? 
    AND data_fim_contrato IS NOT NULL 
    AND data_fim_contrato BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
    ORDER BY data_fim_contrato
");
$stmt->execute([$escola_id]);
$contratos_expirar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Últimas admissões
$stmt = $conn->prepare("
    SELECT id, nome, cargo, data_admissao, foto 
    FROM funcionarios 
    WHERE escola_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$escola_id]);
$ultimas_admissoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distribuição por tipo de funcionário (para gráfico)
$stmt = $conn->prepare("
    SELECT tipo_funcionario, COUNT(*) as total 
    FROM funcionarios 
    WHERE escola_id = ? 
    GROUP BY tipo_funcionario
");
$stmt->execute([$escola_id]);
$distribuicao_tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Evolução de admissões por mês
$stmt = $conn->prepare("
    SELECT MONTH(created_at) as mes, COUNT(*) as total 
    FROM funcionarios 
    WHERE escola_id = ? AND YEAR(created_at) = YEAR(NOW())
    GROUP BY MONTH(created_at)
    ORDER BY mes
");
$stmt->execute([$escola_id]);
$admissoes_mes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard RH | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        
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
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 2.5em; font-weight: bold; color: #006B3E; }
        .stat-icon { font-size: 2em; margin-bottom: 10px; color: #006B3E; }
        .aniversariante-item, .contrato-item, .admissao-item {
            border-left: 3px solid #006B3E;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .aniversariante-item:hover, .contrato-item:hover, .admissao-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>
   <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-line"></i> Dashboard de Recursos Humanos</h2>
            <div>
                <span class="badge bg-primary">Angola</span>
                <span class="badge bg-success">Lei Geral do Trabalho</span>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['total_funcionarios']); ?></div>
                    <div>Total de Funcionários</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['total_professores']); ?></div>
                    <div>Professores</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-building"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['total_administrativos']); ?></div>
                    <div>Administrativos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['funcionarios_ativos']); ?></div>
                    <div>Funcionários Ativos</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['admitidos_mes']); ?></div>
                    <div>Admitidos no Mês</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-bullhorn"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['vagas_abertas']); ?></div>
                    <div>Vagas Abertas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users-viewfinder"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['candidatos_pendentes']); ?></div>
                    <div>Candidatos Pendentes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['avaliacoes_pendentes']); ?></div>
                    <div>Avaliações Pendentes</div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Distribuição por Tipo de Funcionário
                    </div>
                    <div class="card-body">
                        <canvas id="distribuicaoTiposChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-line"></i> Evolução de Admissões (<?php echo date('Y'); ?>)
                    </div>
                    <div class="card-body">
                        <canvas id="admissoesChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Aniversariantes do Mês -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-birthday-cake"></i> Aniversariantes do Mês
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php if (count($aniversariantes) > 0): ?>
                            <?php foreach ($aniversariantes as $a): ?>
                            <div class="aniversariante-item d-flex align-items-center">
                                <img src="../../uploads/funcionarios/fotos/<?php echo $a['foto']; ?>" class="user-avatar me-3" onerror="this.src='../../assets/images/avatar-padrao.png'">
                                <div>
                                    <strong><?php echo htmlspecialchars($a['nome']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($a['cargo']); ?> | Dia <?php echo date('d/m', strtotime($a['data_nascimento'])); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted">Nenhum aniversariante este mês</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Contratos a Expirar -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-file-contract"></i> Contratos a Expirar (30 dias)
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php if (count($contratos_expirar) > 0): ?>
                            <?php foreach ($contratos_expirar as $c): ?>
                                <?php $dias_restantes = ceil((strtotime($c['data_fim_contrato']) - time()) / 86400); ?>
                            <div class="contrato-item">
                                <strong><?php echo htmlspecialchars($c['nome']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($c['cargo']); ?></small><br>
                                <small class="text-warning">
                                    <i class="fas fa-calendar"></i> Termina em: <?php echo date('d/m/Y', strtotime($c['data_fim_contrato'])); ?>
                                    (<?php echo $dias_restantes; ?> dias)
                                </small>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted">Nenhum contrato a expirar nos próximos 30 dias</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Últimas Admissões -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-user-plus"></i> Últimas Admissões
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php if (count($ultimas_admissoes) > 0): ?>
                            <?php foreach ($ultimas_admissoes as $u): ?>
                            <div class="admissao-item d-flex align-items-center">
                                <img src="../../uploads/funcionarios/fotos/<?php echo $u['foto']; ?>" class="user-avatar me-3" onerror="this.src='../../assets/images/avatar-padrao.png'">
                                <div>
                                    <strong><?php echo htmlspecialchars($u['nome']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($u['cargo']); ?></small><br>
                                    <small class="text-success"><i class="fas fa-calendar-check"></i> Admitido em: <?php echo date('d/m/Y', strtotime($u['data_admissao'])); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted">Nenhuma admissão registada</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Ações Rápidas -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-bolt"></i> Ações Rápidas
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <a href="funcionarios/cadastrar.php" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-user-plus"></i> Novo Funcionário
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="recrutamento/vagas.php" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-bullhorn"></i> Nova Vaga
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="avaliacao/periodos.php" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-star"></i> Nova Avaliação
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="formacao/planos.php" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-graduation-cap"></i> Novo Plano de Formação
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        // Gráfico de Distribuição por Tipo
        const ctx1 = document.getElementById('distribuicaoTiposChart').getContext('2d');
        const tiposLabels = <?php echo json_encode(array_column($distribuicao_tipos, 'tipo_funcionario')); ?>;
        const tiposValues = <?php echo json_encode(array_column($distribuicao_tipos, 'total')); ?>;
        
        new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: tiposLabels.map(l => l.charAt(0).toUpperCase() + l.slice(1)),
                datasets: [{
                    data: tiposValues,
                    backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6f42c1', '#fd7e14'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Gráfico de Admissões por Mês
        const ctx2 = document.getElementById('admissoesChart').getContext('2d');
        const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        const admitidosData = Array(12).fill(0);
        
        <?php foreach ($admissoes_mes as $a): ?>
            admitidosData[<?php echo $a['mes'] - 1; ?>] = <?php echo $a['total']; ?>;
        <?php endforeach; ?>
        
        new Chart(ctx2, {
            type: 'line',
            data: {
                labels: meses,
                datasets: [{
                    label: 'Admissões',
                    data: admitidosData,
                    borderColor: '#006B3E',
                    backgroundColor: 'rgba(0, 107, 62, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                }
            }
        });
    </script>
</body>
</html>