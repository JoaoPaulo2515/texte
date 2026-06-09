<?php
// escola/financeiro/folha_pagamento/index.php - Dashboard da Folha de Pagamento
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// VERIFICAR E CRIAR TABELAS
// ============================================

// Tabela de cargos
$check = $conn->query("SHOW TABLES LIKE 'rh_cargos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE rh_cargos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            salario_base DECIMAL(15,2) NOT NULL,
            bonus_fixo DECIMAL(15,2) DEFAULT 0,
            vale_transporte DECIMAL(15,2) DEFAULT 0,
            vale_refeicao DECIMAL(15,2) DEFAULT 0,
            auxilio_saude DECIMAL(15,2) DEFAULT 0,
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            INDEX idx_escola (escola_id)
        )
    ");
}

// Tabela de funcionários
$check = $conn->query("SHOW TABLES LIKE 'rh_funcionarios'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE rh_funcionarios (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            usuario_id INT NOT NULL,
            cargo_id INT,
            numero_funcionario VARCHAR(20) NOT NULL,
            data_admissao DATE NOT NULL,
            data_demissao DATE,
            tipo_contrato ENUM('CLT', 'PJ', 'Estagio', 'Temporario') DEFAULT 'CLT',
            salario_contratual DECIMAL(15,2) NOT NULL,
            banco VARCHAR(100),
            agencia VARCHAR(20),
            conta_bancaria VARCHAR(50),
            pix VARCHAR(100),
            observacoes TEXT,
            status ENUM('ativo', 'inativo', 'ferias', 'licenca') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (cargo_id) REFERENCES rh_cargos(id) ON DELETE SET NULL,
            UNIQUE KEY unique_numero_funcionario (numero_funcionario),
            INDEX idx_escola (escola_id),
            INDEX idx_status (status)
        )
    ");
}

// Tabela de folhas de pagamento
$check = $conn->query("SHOW TABLES LIKE 'rh_folhas_pagamento'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE rh_folhas_pagamento (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            competencia DATE NOT NULL,
            data_processamento DATETIME NOT NULL,
            total_bruto DECIMAL(15,2) DEFAULT 0,
            total_descontos DECIMAL(15,2) DEFAULT 0,
            total_liquido DECIMAL(15,2) DEFAULT 0,
            total_funcionarios INT DEFAULT 0,
            status ENUM('processado', 'pago', 'cancelado') DEFAULT 'processado',
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            UNIQUE KEY unique_competencia (competencia, escola_id),
            INDEX idx_escola (escola_id),
            INDEX idx_competencia (competencia)
        )
    ");
}

// Tabela de itens da folha
$check = $conn->query("SHOW TABLES LIKE 'rh_folha_itens'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE rh_folha_itens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            folha_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            cargo_id INT,
            salario_base DECIMAL(15,2) NOT NULL,
            dias_trabalhados INT DEFAULT 30,
            faltas INT DEFAULT 0,
            horas_extras_50 DECIMAL(10,2) DEFAULT 0,
            horas_extras_100 DECIMAL(10,2) DEFAULT 0,
            adicional_noturno DECIMAL(15,2) DEFAULT 0,
            bonus DECIMAL(15,2) DEFAULT 0,
            vale_transporte DECIMAL(15,2) DEFAULT 0,
            vale_refeicao DECIMAL(15,2) DEFAULT 0,
            auxilio_saude DECIMAL(15,2) DEFAULT 0,
            inss DECIMAL(15,2) DEFAULT 0,
            irrf DECIMAL(15,2) DEFAULT 0,
            outros_descontos DECIMAL(15,2) DEFAULT 0,
            total_proventos DECIMAL(15,2) DEFAULT 0,
            total_descontos DECIMAL(15,2) DEFAULT 0,
            valor_liquido DECIMAL(15,2) DEFAULT 0,
            observacoes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (folha_id) REFERENCES rh_folhas_pagamento(id) ON DELETE CASCADE,
            FOREIGN KEY (funcionario_id) REFERENCES rh_funcionarios(id) ON DELETE CASCADE,
            FOREIGN KEY (cargo_id) REFERENCES rh_cargos(id) ON DELETE SET NULL,
            INDEX idx_folha (folha_id),
            INDEX idx_funcionario (funcionario_id)
        )
    ");
}

// ============================================
// BUSCAR ESTATÍSTICAS
// ============================================

// Total de funcionários ativos
$stmt = $conn->prepare("
    SELECT COUNT(*) as total FROM rh_funcionarios 
    WHERE escola_id = :escola_id AND status = 'ativo'
");
$stmt->execute([':escola_id' => $escola_id]);
$total_funcionarios = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de cargos
$stmt = $conn->prepare("
    SELECT COUNT(*) as total FROM rh_cargos 
    WHERE escola_id = :escola_id AND status = 'ativo'
");
$stmt->execute([':escola_id' => $escola_id]);
$total_cargos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Última folha processada
$stmt = $conn->prepare("
    SELECT * FROM rh_folhas_pagamento 
    WHERE escola_id = :escola_id 
    ORDER BY competencia DESC LIMIT 1
");
$stmt->execute([':escola_id' => $escola_id]);
$ultima_folha = $stmt->fetch(PDO::FETCH_ASSOC);

// Próxima competência
$proxima_competencia = date('Y-m-d', strtotime('first day of next month'));

// Resumo de salários
$stmt = $conn->prepare("
    SELECT 
        SUM(salario_contratual) as total_salarios,
        AVG(salario_contratual) as media_salario
    FROM rh_funcionarios 
    WHERE escola_id = :escola_id AND status = 'ativo'
");
$stmt->execute([':escola_id' => $escola_id]);
$resumo_salarios = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar últimas 6 folhas para gráfico
$stmt = $conn->prepare("
    SELECT competencia, total_bruto, total_liquido 
    FROM rh_folhas_pagamento 
    WHERE escola_id = :escola_id 
    ORDER BY competencia DESC LIMIT 6
");
$stmt->execute([':escola_id' => $escola_id]);
$folhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$folhas = array_reverse($folhas);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Folha de Pagamento | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .btn-ajuda { background: #17a2b8; color: white; border: none; }
        .btn-ajuda:hover { background: #138496; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        
        .info-card { background: #e8f5e9; border-radius: 15px; padding: 15px; margin-bottom: 20px; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .chart-container { position: relative; height: 300px; width: 100%; }
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
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuFinanceiro">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i> <span>Financeiro</span>
                </a>
                <ul class="nav-submenu show" id="submenuFinanceiro">
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
                    <li class="nav-item"><a href="funcionarios.php" class="nav-link"><i class="fas fa-users"></i> Funcionários</a></li>
                    <li class="nav-item"><a href="cargos.php" class="nav-link"><i class="fas fa-briefcase"></i> Cargos</a></li>
                    <li class="nav-item"><a href="processar.php" class="nav-link"><i class="fas fa-calculator"></i> Processar</a></li>
                    <li class="nav-item"><a href="holerites.php" class="nav-link"><i class="fas fa-receipt"></i> Holerites</a></li>
                    <li class="nav-item"><a href="relatorios.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
                    <li class="nav-item"><a href="configuracoes.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="funcionarios.php" class="btn btn-info btn-sm me-2">
                    <i class="fas fa-users"></i> Funcionários
                </a>
                <a href="cargos.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-briefcase"></i> Cargos
                </a>
                <a href="processar.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-calculator"></i> Processar Folha
                </a>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Cards de Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_funcionarios; ?></div>
                <div class="stat-label">Funcionários Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_cargos; ?></div>
                <div class="stat-label">Cargos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($resumo_salarios['total_salarios'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total de Salários</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($resumo_salarios['media_salario'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Média Salarial</div>
            </div>
        </div>
        
        <!-- Última Folha Processada -->
        <div class="info-card">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5><i class="fas fa-history"></i> Última Folha Processada</h5>
                    <?php if ($ultima_folha): ?>
                        <p class="mb-1"><strong>Competência:</strong> <?php echo date('m/Y', strtotime($ultima_folha['competencia'])); ?></p>
                        <p class="mb-1"><strong>Total Bruto:</strong> <?php echo number_format($ultima_folha['total_bruto'], 2, ',', '.'); ?> Kz</p>
                        <p class="mb-1"><strong>Total Líquido:</strong> <?php echo number_format($ultima_folha['total_liquido'], 2, ',', '.'); ?> Kz</p>
                        <p class="mb-0"><strong>Status:</strong> <span class="badge bg-success"><?php echo ucfirst($ultima_folha['status']); ?></span></p>
                    <?php else: ?>
                        <p class="text-muted mb-0">Nenhuma folha processada ainda.</p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 text-end">
                    <p class="mb-1"><strong>Próxima Competência:</strong> <?php echo date('m/Y', strtotime($proxima_competencia)); ?></p>
                    <a href="processar.php" class="btn btn-primary mt-2">
                        <i class="fas fa-play"></i> Processar Nova Folha
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Evolução -->
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> Evolução da Folha de Pagamento</div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="evolucaoChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Últimos Holerites -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-receipt"></i> Últimos Holerites</span>
                <a href="holerites.php" class="btn btn-sm btn-primary">Ver Todos</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Competência</th>
                                <th>Funcionário</th>
                                <th>Cargo</th>
                                <th>Salário Base</th>
                                <th>Líquido</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $conn->prepare("
                                SELECT fi.*, f.competencia, f.status as folha_status,
                                       rf.nome as funcionario_nome, rc.nome as cargo_nome
                                FROM rh_folha_itens fi
                                JOIN rh_folhas_pagamento f ON f.id = fi.folha_id
                                JOIN rh_funcionarios rf ON rf.id = fi.funcionario_id
                                LEFT JOIN rh_cargos rc ON rc.id = fi.cargo_id
                                WHERE fi.escola_id = :escola_id
                                ORDER BY f.competencia DESC LIMIT 10
                            ");
                            $stmt->execute([':escola_id' => $escola_id]);
                            $ultimos_holerites = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <?php foreach ($ultimos_holerites as $holerite): ?>
                            <tr>
                                <td><?php echo date('m/Y', strtotime($holerite['competencia'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($holerite['funcionario_nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($holerite['cargo_nome'] ?? '-'); ?></td>
                                <td><?php echo number_format($holerite['salario_base'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($holerite['valor_liquido'], 2, ',', '.'); ?> Kz</div>
                                <td>
                                    <a href="holerites.php?view=<?php echo $holerite['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="holerites.php?pdf=<?php echo $holerite['id']; ?>" class="btn btn-sm btn-danger" target="_blank">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Folha de Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é a Folha de Pagamento?</h6>
                    <p>Módulo para gestão completa de salários, funcionários, cargos e processamento da folha mensal.</p>
                    
                    <h6><i class="fas fa-chart-line text-success"></i> Funcionalidades:</h6>
                    <ul>
                        <li><strong>Funcionários:</strong> Cadastro e gestão de funcionários.</li>
                        <li><strong>Cargos:</strong> Definição de cargos e salários base.</li>
                        <li><strong>Processar:</strong> Cálculo automático da folha mensal.</li>
                        <li><strong>Holerites:</strong> Visualização e impressão de holerites.</li>
                        <li><strong>Relatórios:</strong> Análises e demonstrativos.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Mantenha os dados dos funcionários atualizados.</li>
                        <li>Processe a folha sempre no início do mês.</li>
                        <li>Verifique os cálculos antes de finalizar.</li>
                        <li>Emita os holerites para os funcionários.</li>
                    </ul>
                    
                    <hr>
                    <p class="text-muted small mb-0"><i class="fas fa-clock"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
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
        
        // Gráfico de Evolução
        const ctx = document.getElementById('evolucaoChart').getContext('2d');
        const competencias = <?php echo json_encode(array_map(function($f) { return date('m/Y', strtotime($f['competencia'])); }, $folhas)); ?>;
        const valoresBrutos = <?php echo json_encode(array_column($folhas, 'total_bruto')); ?>;
        const valoresLiquidos = <?php echo json_encode(array_column($folhas, 'total_liquido')); ?>;
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: competencias,
                datasets: [
                    {
                        label: 'Total Bruto',
                        data: valoresBrutos,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Total Líquido',
                        data: valoresLiquidos,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('pt-AO') + ' Kz';
                            }
                        }
                    }
                }
            }
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>