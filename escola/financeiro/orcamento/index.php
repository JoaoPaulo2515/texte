<?php
// escola/financeiro/orcamento/index.php - Planejamento Orçamentário
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

// ============================================
// VERIFICAR E CRIAR TABELAS
// ============================================

// Tabela de orçamento
$check = $conn->query("SHOW TABLES LIKE 'escola_orcamento'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_orcamento (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            ano INT NOT NULL,
            categoria VARCHAR(50) NOT NULL,
            tipo ENUM('receita', 'despesa') NOT NULL,
            valor_previsto DECIMAL(15,2) NOT NULL,
            valor_realizado DECIMAL(15,2) DEFAULT 0,
            observacoes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            UNIQUE KEY unique_orcamento (ano, categoria, tipo, escola_id)
        )
    ");
}

// Tabela de categorias orçamentárias
$check = $conn->query("SHOW TABLES LIKE 'escola_categorias_orcamento'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_categorias_orcamento (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            tipo ENUM('receita', 'despesa') NOT NULL,
            cor VARCHAR(7) DEFAULT '#006B3E',
            ordem INT DEFAULT 0,
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            UNIQUE KEY unique_categoria (nome, tipo, escola_id)
        )
    ");
    
    // Inserir categorias padrão
    $categorias_padrao = [
        ['Mensalidades', 'receita', '#28a745', 1],
        ['Matrículas', 'receita', '#20c997', 2],
        ['Taxas Escolares', 'receita', '#17a2b8', 3],
        ['Doações', 'receita', '#ffc107', 4],
        ['Outras Receitas', 'receita', '#6f42c1', 5],
        ['Salários', 'despesa', '#dc3545', 1],
        ['Material Escolar', 'despesa', '#fd7e14', 2],
        ['Utilidades (Água/Luz)', 'despesa', '#e83e8c', 3],
        ['Manutenção', 'despesa', '#6c757d', 4],
        ['Impostos', 'despesa', '#343a40', 5],
        ['Outras Despesas', 'despesa', '#adb5bd', 6]
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO escola_categorias_orcamento (escola_id, nome, tipo, cor, ordem)
        VALUES (:escola_id, :nome, :tipo, :cor, :ordem)
    ");
    foreach ($categorias_padrao as $cat) {
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $cat[0],
            ':tipo' => $cat[1],
            ':cor' => $cat[2],
            ':ordem' => $cat[3]
        ]);
    }
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

$ano = $_GET['ano'] ?? date('Y');
$mensagem = '';
$erro = '';

// Salvar planejamento
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_planejamento') {
    $categorias = $_POST['categorias'] ?? [];
    $valores_previstos = $_POST['valor_previsto'] ?? [];
    
    $count = 0;
    foreach ($categorias as $categoria_id => $tipo) {
        $categoria_nome = $_POST['categoria_nome'][$categoria_id] ?? '';
        $valor_previsto = str_replace(',', '', $valores_previstos[$categoria_id] ?? 0);
        
        if ($valor_previsto > 0) {
            // Verificar se já existe
            $check = $conn->prepare("
                SELECT id FROM escola_orcamento 
                WHERE escola_id = :escola_id AND ano = :ano AND categoria = :categoria AND tipo = :tipo
            ");
            $check->execute([
                ':escola_id' => $escola_id,
                ':ano' => $ano,
                ':categoria' => $categoria_nome,
                ':tipo' => $tipo
            ]);
            
            if ($check->rowCount() > 0) {
                $stmt = $conn->prepare("
                    UPDATE escola_orcamento 
                    SET valor_previsto = :valor_previsto, updated_at = NOW()
                    WHERE escola_id = :escola_id AND ano = :ano AND categoria = :categoria AND tipo = :tipo
                ");
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO escola_orcamento (escola_id, ano, categoria, tipo, valor_previsto)
                    VALUES (:escola_id, :ano, :categoria, :tipo, :valor_previsto)
                ");
            }
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':ano' => $ano,
                ':categoria' => $categoria_nome,
                ':tipo' => $tipo,
                ':valor_previsto' => $valor_previsto
            ]);
            $count++;
        }
    }
    
    $_SESSION['mensagem'] = "Planejamento salvo com sucesso! ($count itens)";
    header("Location: index.php?ano=$ano");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar categorias
$categorias_receita = $conn->prepare("
    SELECT * FROM escola_categorias_orcamento 
    WHERE escola_id = :escola_id AND tipo = 'receita' AND status = 'ativo'
    ORDER BY ordem, nome
");
$categorias_receita->execute([':escola_id' => $escola_id]);
$categorias_receita = $categorias_receita->fetchAll(PDO::FETCH_ASSOC);

$categorias_despesa = $conn->prepare("
    SELECT * FROM escola_categorias_orcamento 
    WHERE escola_id = :escola_id AND tipo = 'despesa' AND status = 'ativo'
    ORDER BY ordem, nome
");
$categorias_despesa->execute([':escola_id' => $escola_id]);
$categorias_despesa = $categorias_despesa->fetchAll(PDO::FETCH_ASSOC);

// Buscar valores já cadastrados
$orcamentos = $conn->prepare("
    SELECT * FROM escola_orcamento 
    WHERE escola_id = :escola_id AND ano = :ano
");
$orcamentos->execute([':escola_id' => $escola_id, ':ano' => $ano]);
$orcamentos = $orcamentos->fetchAll(PDO::FETCH_ASSOC);

// Mapear valores por categoria
$valores_previstos = [];
foreach ($orcamentos as $o) {
    $valores_previstos[$o['categoria'] . '_' . $o['tipo']] = $o['valor_previsto'];
}

// Calcular totais
$total_receitas_previsto = 0;
$total_despesas_previsto = 0;

foreach ($categorias_receita as $cat) {
    $total_receitas_previsto += $valores_previstos[$cat['nome'] . '_receita'] ?? 0;
}
foreach ($categorias_despesa as $cat) {
    $total_despesas_previsto += $valores_previstos[$cat['nome'] . '_despesa'] ?? 0;
}

$saldo_previsto = $total_receitas_previsto - $total_despesas_previsto;

// Buscar anos disponíveis
$anos_disponiveis = $conn->prepare("
    SELECT DISTINCT ano FROM escola_orcamento 
    WHERE escola_id = :escola_id 
    UNION SELECT YEAR(CURDATE()) as ano
    ORDER BY ano DESC
");
$anos_disponiveis->execute([':escola_id' => $escola_id]);
$anos_disponiveis = $anos_disponiveis->fetchAll(PDO::FETCH_COLUMN);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planejamento Orçamentário | SIGE Angola</title>
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
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        
        .receita-row { background-color: #e8f5e9; }
        .despesa-row { background-color: #ffebee; }
        
        .chart-container { position: relative; height: 350px; width: 100%; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .valor-input { max-width: 150px; display: inline-block; }
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
                    <li class="nav-item"><a href="../contas_receber/index.php" class="nav-link"><i class="fas fa-arrow-up"></i> Contas a Receber</a></li>
                    <li class="nav-item"><a href="../contas_pagar/index.php" class="nav-link"><i class="fas fa-arrow-down"></i> Contas a Pagar</a></li>
                    <li class="nav-item"><a href="../fluxo_caixa/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                    <li class="nav-item"><a href="../balancete/index.php" class="nav-link"><i class="fas fa-balance-scale"></i> Balancete</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-chart-pie"></i> Orçamento</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-chart-pie"></i> Planejamento Orçamentário
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="categorias.php" class="btn btn-info btn-sm me-2">
                    <i class="fas fa-tags"></i> Categorias
                </a>
                <a href="executado.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-chart-line"></i> Executado
                </a>
                <a href="desvios.php" class="btn btn-warning btn-sm">
                    <i class="fas fa-exclamation-triangle"></i> Desvios
                </a>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Filtro de Ano -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label>Ano de Referência</label>
                    <select name="ano" class="form-control" onchange="this.form.submit()">
                        <?php for ($i = date('Y') - 2; $i <= date('Y') + 2; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($total_receitas_previsto, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Receitas Previstas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo number_format($total_despesas_previsto, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Despesas Previstas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo $saldo_previsto >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($saldo_previsto, 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Saldo Previsto</div>
            </div>
        </div>
        
        <!-- Formulário de Planejamento -->
        <form method="POST">
            <input type="hidden" name="acao" value="salvar_planejamento">
            
            <!-- Receitas -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-arrow-up"></i> Receitas Previstas - <?php echo $ano; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Categoria</th>
                                    <th width="200">Valor Previsto (Kz)</th>
                                    <th width="100">% do Total</th>
                                </tr>
                            </thead>
                            <tbody class="receita-row">
                                <?php foreach ($categorias_receita as $cat): 
                                    $valor = $valores_previstos[$cat['nome'] . '_receita'] ?? 0;
                                    $percentual = $total_receitas_previsto > 0 ? round(($valor / $total_receitas_previsto) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-circle" style="color: <?php echo $cat['cor']; ?>"></i>
                                        <?php echo htmlspecialchars($cat['nome']); ?>
                                        <input type="hidden" name="categorias[<?php echo $cat['id']; ?>]" value="receita">
                                        <input type="hidden" name="categoria_nome[<?php echo $cat['id']; ?>]" value="<?php echo htmlspecialchars($cat['nome']); ?>">
                                     </div>
                                    </div>
                                    <td>
                                        <input type="number" step="0.01" name="valor_previsto[<?php echo $cat['id']; ?>]" class="form-control valor-input" value="<?php echo $valor; ?>">
                                     </div>
                                    </div>
                                    <td class="text-center"><?php echo $percentual; ?>%</div>
                                 </div>
                                <?php endforeach; ?>
                                <tr class="table-secondary">
                                    <td><strong>TOTAL RECEITAS</strong></td>
                                    <td><strong><?php echo number_format($total_receitas_previsto, 2, ',', '.'); ?> Kz</strong></td>
                                    <td class="text-center"><strong>100%</strong></td>
                                </tr>
                            </tbody>
                         </div>
                    </div>
                </div>
            </div>
            
            <!-- Despesas -->
            <div class="card mt-4">
                <div class="card-header bg-danger text-white">
                    <i class="fas fa-arrow-down"></i> Despesas Previstas - <?php echo $ano; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Categoria</th>
                                    <th width="200">Valor Previsto (Kz)</th>
                                    <th width="100">% do Total</th>
                                </tr>
                            </thead>
                            <tbody class="despesa-row">
                                <?php foreach ($categorias_despesa as $cat): 
                                    $valor = $valores_previstos[$cat['nome'] . '_despesa'] ?? 0;
                                    $percentual = $total_despesas_previsto > 0 ? round(($valor / $total_despesas_previsto) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-circle" style="color: <?php echo $cat['cor']; ?>"></i>
                                        <?php echo htmlspecialchars($cat['nome']); ?>
                                        <input type="hidden" name="categorias[<?php echo $cat['id']; ?>]" value="despesa">
                                        <input type="hidden" name="categoria_nome[<?php echo $cat['id']; ?>]" value="<?php echo htmlspecialchars($cat['nome']); ?>">
                                     </div>
                                    </div>
                                    <td>
                                        <input type="number" step="0.01" name="valor_previsto[<?php echo $cat['id']; ?>]" class="form-control valor-input" value="<?php echo $valor; ?>">
                                     </div>
                                    </div>
                                    <td class="text-center"><?php echo $percentual; ?>%</div>
                                 </div>
                                <?php endforeach; ?>
                                <tr class="table-secondary">
                                    <td><strong>TOTAL DESPESAS</strong></td>
                                    <td><strong><?php echo number_format($total_despesas_previsto, 2, ',', '.'); ?> Kz</strong></td>
                                    <td class="text-center"><strong>100%</strong></td>
                                 </tr>
                            </tbody>
                         </div>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Salvar Planejamento
                </button>
            </div>
        </form>
        
        <!-- Gráfico de Distribuição -->
        <div class="card mt-4">
            <div class="card-header"><i class="fas fa-chart-pie"></i> Distribuição Orçamentária</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="chart-container">
                            <canvas id="receitasChart"></canvas>
                        </div>
                        <p class="text-center text-success mt-2"><strong>Receitas</strong></p>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-container">
                            <canvas id="despesasChart"></canvas>
                        </div>
                        <p class="text-center text-danger mt-2"><strong>Despesas</strong></p>
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Sobre o Planejamento Orçamentário</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é o Planejamento Orçamentário?</h6>
                    <p>O planejamento orçamentário permite definir metas financeiras para receitas e despesas da escola para um determinado ano.</p>
                    
                    <h6><i class="fas fa-chart-line text-success"></i> Como usar:</h6>
                    <ul>
                        <li>Defina o valor previsto para cada categoria de receita e despesa.</li>
                        <li>Os totais são calculados automaticamente.</li>
                        <li>Após preencher, clique em "Salvar Planejamento".</li>
                        <li>Utilize os gráficos para visualizar a distribuição.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Baseie-se em anos anteriores para definir metas realistas.</li>
                        <li>Compare o planejado com o executado periodicamente.</li>
                        <li>Revise o orçamento trimestralmente para ajustes.</li>
                        <li>Categorias bem definidas facilitam a análise.</li>
                    </ul>
                    
                    <hr>
                    <p class="text-muted small mb-0"><i class="fas fa-clock"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
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
        
        // Gráfico de Receitas
        const ctx1 = document.getElementById('receitasChart').getContext('2d');
        const receitasLabels = <?php echo json_encode(array_column($categorias_receita, 'nome')); ?>;
        const receitasValues = <?php echo json_encode(array_map(function($cat) use ($valores_previstos) {
            return $valores_previstos[$cat['nome'] . '_receita'] ?? 0;
        }, $categorias_receita)); ?>;
        
        new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: receitasLabels,
                datasets: [{
                    data: receitasValues,
                    backgroundColor: <?php echo json_encode(array_column($categorias_receita, 'cor')); ?>
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = receitasValues.reduce((a, b) => a + b, 0);
                                const percentagem = total > 0 ? ((context.raw / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz (' + percentagem + '%)';
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de Despesas
        const ctx2 = document.getElementById('despesasChart').getContext('2d');
        const despesasLabels = <?php echo json_encode(array_column($categorias_despesa, 'nome')); ?>;
        const despesasValues = <?php echo json_encode(array_map(function($cat) use ($valores_previstos) {
            return $valores_previstos[$cat['nome'] . '_despesa'] ?? 0;
        }, $categorias_despesa)); ?>;
        
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: despesasLabels,
                datasets: [{
                    data: despesasValues,
                    backgroundColor: <?php echo json_encode(array_column($categorias_despesa, 'cor')); ?>
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = despesasValues.reduce((a, b) => a + b, 0);
                                const percentagem = total > 0 ? ((context.raw / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz (' + percentagem + '%)';
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