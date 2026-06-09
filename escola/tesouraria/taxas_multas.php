<?php
// escola/tesouraria/taxas_multas.php - Gestão de Taxas e Multas

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
// TABELAS NECESSÁRIAS
// ============================================

$sql_criar_tabelas = "
CREATE TABLE IF NOT EXISTS taxas_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    escola_id INT NOT NULL,
    nome VARCHAR(100) NOT NULL,
    tipo ENUM('taxa', 'multa', 'juros') DEFAULT 'taxa',
    valor DECIMAL(10,2) NOT NULL,
    tipo_cobranca ENUM('percentual', 'fixo') DEFAULT 'percentual',
    aplicacao ENUM('mensalidade', 'outros_pagamentos', 'todos') DEFAULT 'mensalidade',
    dias_atraso INT DEFAULT 0,
    descricao TEXT,
    ativo TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS taxas_aplicadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    escola_id INT NOT NULL,
    pagamento_id INT NULL,
    mensalidade_id INT NULL,
    aluno_id INT NOT NULL,
    tipo_taxa VARCHAR(50) NOT NULL,
    valor_original DECIMAL(10,2) NOT NULL,
    valor_taxa DECIMAL(10,2) NOT NULL,
    dias_atraso INT NOT NULL,
    data_aplicacao DATE NOT NULL,
    status ENUM('pendente', 'pago', 'cancelado') DEFAULT 'pendente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES estudantes(id) ON DELETE CASCADE,
    FOREIGN KEY (pagamento_id) REFERENCES pagamentos(id) ON DELETE SET NULL,
    FOREIGN KEY (mensalidade_id) REFERENCES mensalidades(id) ON DELETE SET NULL
);
";
$conn->exec($sql_criar_tabelas);

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'config';

// Adicionar/Editar configuração de taxa
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'save_config') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $nome = trim($_POST['nome']);
        $tipo = $_POST['tipo'];
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $tipo_cobranca = $_POST['tipo_cobranca'];
        $aplicacao = $_POST['aplicacao'];
        $dias_atraso = (int)$_POST['dias_atraso'];
        $descricao = trim($_POST['descricao']);
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        if ($valor <= 0 && $tipo_cobranca == 'fixo') {
            $error = "Valor inválido.";
        } elseif ($valor <= 0 || $valor > 100 && $tipo_cobranca == 'percentual') {
            $error = "Percentual inválido (1-100%).";
        } elseif (empty($nome)) {
            $error = "Nome é obrigatório.";
        } else {
            try {
                if ($id > 0) {
                    $sql = "UPDATE taxas_config SET 
                                nome = :nome, tipo = :tipo, valor = :valor, 
                                tipo_cobranca = :tipo_cobranca, aplicacao = :aplicacao, 
                                dias_atraso = :dias_atraso, descricao = :descricao, ativo = :ativo 
                            WHERE id = :id AND escola_id = :escola_id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':id' => $id, ':escola_id' => $escola_id, ':nome' => $nome,
                        ':tipo' => $tipo, ':valor' => $valor, ':tipo_cobranca' => $tipo_cobranca,
                        ':aplicacao' => $aplicacao, ':dias_atraso' => $dias_atraso,
                        ':descricao' => $descricao, ':ativo' => $ativo
                    ]);
                    $success = "Configuração atualizada com sucesso!";
                } else {
                    $sql = "INSERT INTO taxas_config (escola_id, nome, tipo, valor, tipo_cobranca, aplicacao, dias_atraso, descricao, ativo) 
                            VALUES (:escola_id, :nome, :tipo, :valor, :tipo_cobranca, :aplicacao, :dias_atraso, :descricao, :ativo)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':escola_id' => $escola_id, ':nome' => $nome, ':tipo' => $tipo,
                        ':valor' => $valor, ':tipo_cobranca' => $tipo_cobranca, ':aplicacao' => $aplicacao,
                        ':dias_atraso' => $dias_atraso, ':descricao' => $descricao, ':ativo' => $ativo
                    ]);
                    $success = "Configuração adicionada com sucesso!";
                }
            } catch (Exception $e) {
                $error = "Erro ao salvar: " . $e->getMessage();
            }
        }
    }
    
    // Excluir configuração
    elseif (isset($_POST['action']) && $_POST['action'] == 'delete_config') {
        $id = (int)$_POST['id'];
        $sql = "DELETE FROM taxas_config WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $success = "Configuração removida!";
    }
    
    // Aplicar taxas em lote
    elseif (isset($_POST['action']) && $_POST['action'] == 'aplicar_taxas') {
        $config_id = (int)$_POST['config_id'];
        $data_referencia = $_POST['data_referencia'];
        
        // Buscar configuração
        $sql_config = "SELECT * FROM taxas_config WHERE id = :id AND escola_id = :escola_id AND ativo = 1";
        $stmt_config = $conn->prepare($sql_config);
        $stmt_config->execute([':id' => $config_id, ':escola_id' => $escola_id]);
        $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            $error = "Configuração não encontrada ou inativa.";
        } else {
            // Buscar mensalidades em atraso
            $sql_mensalidades = "SELECT m.*, e.nome as aluno_nome 
                                 FROM mensalidades m
                                 JOIN estudantes e ON e.id = m.aluno_id
                                 WHERE m.escola_id = :escola_id 
                                 AND m.status IN ('pendente', 'parcial')
                                 AND m.data_vencimento < :data_ref
                                 AND NOT EXISTS (SELECT 1 FROM taxas_aplicadas ta WHERE ta.mensalidade_id = m.id AND ta.tipo_taxa = :tipo_taxa)";
            $stmt_mensalidades = $conn->prepare($sql_mensalidades);
            $stmt_mensalidades->execute([
                ':escola_id' => $escola_id,
                ':data_ref' => $data_referencia,
                ':tipo_taxa' => $config['nome']
            ]);
            $mensalidades = $stmt_mensalidades->fetchAll(PDO::FETCH_ASSOC);
            
            $contador = 0;
            foreach ($mensalidades as $mensalidade) {
                $dias_atraso = (strtotime($data_referencia) - strtotime($mensalidade['data_vencimento'])) / (60 * 60 * 24);
                if ($dias_atraso >= $config['dias_atraso']) {
                    if ($config['tipo_cobranca'] == 'percentual') {
                        $valor_taxa = ($mensalidade['valor_total'] - $mensalidade['valor_pago']) * ($config['valor'] / 100);
                    } else {
                        $valor_taxa = $config['valor'];
                    }
                    
                    $sql_insert = "INSERT INTO taxas_aplicadas (escola_id, mensalidade_id, aluno_id, tipo_taxa, valor_original, valor_taxa, dias_atraso, data_aplicacao) 
                                   VALUES (:escola_id, :mensalidade_id, :aluno_id, :tipo_taxa, :valor_original, :valor_taxa, :dias_atraso, :data_aplicacao)";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':escola_id' => $escola_id,
                        ':mensalidade_id' => $mensalidade['id'],
                        ':aluno_id' => $mensalidade['aluno_id'],
                        ':tipo_taxa' => $config['nome'],
                        ':valor_original' => $mensalidade['valor_total'] - $mensalidade['valor_pago'],
                        ':valor_taxa' => $valor_taxa,
                        ':dias_atraso' => $dias_atraso,
                        ':data_aplicacao' => $data_referencia
                    ]);
                    $contador++;
                }
            }
            $success = "Taxas aplicadas com sucesso! Total: $contador aplicações.";
        }
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar configurações
$sql_configs = "SELECT * FROM taxas_config WHERE escola_id = :escola_id ORDER BY tipo, nome ASC";
$stmt_configs = $conn->prepare($sql_configs);
$stmt_configs->execute([':escola_id' => $escola_id]);
$configuracoes = $stmt_configs->fetchAll(PDO::FETCH_ASSOC);

// Buscar taxas aplicadas
$sql_aplicadas = "SELECT ta.*, e.nome as aluno_nome, e.matricula, 
                         m.mes_referencia, m.ano_referencia
                  FROM taxas_aplicadas ta
                  JOIN estudantes e ON e.id = ta.aluno_id
                  LEFT JOIN mensalidades m ON m.id = ta.mensalidade_id
                  WHERE ta.escola_id = :escola_id 
                  ORDER BY ta.created_at DESC 
                  LIMIT 50";
$stmt_aplicadas = $conn->prepare($sql_aplicadas);
$stmt_aplicadas->execute([':escola_id' => $escola_id]);
$taxas_aplicadas = $stmt_aplicadas->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$sql_stats = "SELECT 
                    COUNT(*) as total,
                    SUM(valor_taxa) as total_valor,
                    COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
                    COUNT(CASE WHEN status = 'pago' THEN 1 END) as pagos
              FROM taxas_aplicadas 
              WHERE escola_id = :escola_id";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([':escola_id' => $escola_id]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getTipoBadge($tipo) {
    switch ($tipo) {
        case 'taxa': return '<span class="badge bg-info">Taxa</span>';
        case 'multa': return '<span class="badge bg-warning text-dark">Multa</span>';
        case 'juros': return '<span class="badge bg-danger">Juros</span>';
        default: return '<span class="badge bg-secondary">' . $tipo . '</span>';
    }
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pendente': return '<span class="badge bg-warning text-dark">Pendente</span>';
        case 'pago': return '<span class="badge bg-success">Pago</span>';
        case 'cancelado': return '<span class="badge bg-danger">Cancelado</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Taxas e Multas | Tesouraria | SIGE Angola</title>
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
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-percent"></i> Taxas e Multas</h2>
                <p class="text-muted">Configuração e aplicação de taxas, multas e juros</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaConfig">
                    <i class="fas fa-plus"></i> Nova Configuração
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
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($stats['total'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="stat-label"><i class="fas fa-file-invoice"></i> Total de Aplicações</div>
                    <small>Taxas/multas aplicadas</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($stats['total_valor'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-money-bill"></i> Valor Total em Taxas</div>
                    <small>Valor acumulado</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($stats['pendentes'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="stat-label"><i class="fas fa-clock text-warning"></i> Pendentes</div>
                    <small>Aguardando pagamento</small>
                </div>
            </div>
        </div>
        
        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab == 'config' ? 'active' : ''; ?>" href="?tab=config">
                    <i class="fas fa-cog"></i> Configurações
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab == 'aplicadas' ? 'active' : ''; ?>" href="?tab=aplicadas">
                    <i class="fas fa-list"></i> Taxas Aplicadas
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab == 'aplicar' ? 'active' : ''; ?>" href="?tab=aplicar">
                    <i class="fas fa-play"></i> Aplicar Taxas
                </a>
            </li>
        </ul>
        
        <!-- CONFIGURAÇÕES -->
        <?php if ($active_tab == 'config'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-cog"></i> Configurações de Taxas e Multas</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Valor</th>
                                <th>Cobrança</th>
                                <th>Aplicação</th>
                                <th>Dias Atraso</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($configuracoes as $config): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($config['nome']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($config['descricao']); ?></small></td>
                                <td><?php echo getTipoBadge($config['tipo']); ?></small></td>
                                <td>
                                    <?php if ($config['tipo_cobranca'] == 'percentual'): ?>
                                        <?php echo number_format($config['valor'], 1, ',', '.'); ?>%
                                    <?php else: ?>
                                        <?php echo formatarMoeda($config['valor']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $config['tipo_cobranca'] == 'percentual' ? 'Percentual' : 'Valor Fixo'; ?></td>
                                <td><?php echo ucfirst($config['aplicacao']); ?></td>
                                <td><?php echo $config['dias_atraso']; ?> dias</small></td>
                                <td>
                                    <?php if ($config['ativo']): ?>
                                        <span class="badge bg-success">Ativo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editarConfig(<?php echo htmlspecialchars(json_encode($config)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="excluirConfig(<?php echo $config['id']; ?>, '<?php echo htmlspecialchars($config['nome']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- TAXAS APLICADAS -->
        <?php if ($active_tab == 'aplicadas'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Taxas e Multas Aplicadas</h5>
            </div>
            <div class="card-body">
                <?php if (empty($taxas_aplicadas)): ?>
                    <div class="alert alert-info text-center">Nenhuma taxa aplicada ainda.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Aluno</th>
                                    <th>Tipo</th>
                                    <th>Valor Original</th>
                                    <th>Valor Taxa</th>
                                    <th>Dias Atraso</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($taxas_aplicadas as $taxa): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($taxa['data_aplicacao'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($taxa['aluno_nome']); ?></strong><br>
                                        <small class="text-muted">Mat: <?php echo $taxa['matricula']; ?></small>
                                    </td>
                                    <td><?php echo getTipoBadge($taxa['tipo_taxa']); ?></td>
                                    <td><?php echo formatarMoeda($taxa['valor_original']); ?></td>
                                    <td class="text-danger fw-bold"><?php echo formatarMoeda($taxa['valor_taxa']); ?></td>
                                    <td><?php echo $taxa['dias_atraso']; ?> dias</small></td>
                                    <td><?php echo getStatusBadge($taxa['status']); ?></td>
                                    <td>
                                        <?php if ($taxa['status'] == 'pendente'): ?>
                                        <button class="btn btn-sm btn-success" onclick="registrarPagamentoTaxa(<?php echo $taxa['id']; ?>, '<?php echo formatarMoeda($taxa['valor_taxa']); ?>')">
                                            <i class="fas fa-money-bill"></i> Pagar
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAL:</td>
                                    <td><?php echo formatarMoeda(array_sum(array_column($taxas_aplicadas, 'valor_taxa'))); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- APLICAR TAXAS EM LOTE -->
        <?php if ($active_tab == 'aplicar'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-play"></i> Aplicar Taxas em Lote</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="aplicar_taxas">
                    <div class="col-md-4">
                        <label class="form-label">Configuração <span class="text-danger">*</span></label>
                        <select name="config_id" class="form-select" required>
                            <option value="">Selecione uma configuração</option>
                            <?php foreach ($configuracoes as $config): ?>
                            <option value="<?php echo $config['id']; ?>">
                                <?php echo $config['nome']; ?> - <?php echo $config['tipo_cobranca'] == 'percentual' ? $config['valor'] . '%' : formatarMoeda($config['valor']); ?>
                                (Atraso: <?php echo $config['dias_atraso']; ?> dias)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Data de Referência</label>
                        <input type="date" name="data_referencia" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100" onclick="return confirm('Deseja aplicar esta taxa a todas as mensalidades em atraso?')">
                            <i class="fas fa-play"></i> Aplicar Taxas
                        </button>
                    </div>
                </form>
                
                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle"></i>
                    <strong>Como funciona:</strong>
                    <ul class="mb-0 mt-2">
                        <li>As taxas serão aplicadas automaticamente a todas as mensalidades em atraso</li>
                        <li>Só serão aplicadas se a configuração estiver ativa</li>
                        <li>Não serão aplicadas novamente se já tiverem sido aplicadas anteriormente</li>
                        <li>O valor da taxa é calculado sobre o valor pendente da mensalidade</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Nova/Editar Configuração -->
    <div class="modal fade" id="modalConfig" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalConfigTitle"><i class="fas fa-plus-circle"></i> Nova Configuração</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_config">
                    <input type="hidden" name="id" id="config_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nome <span class="text-danger">*</span></label>
                            <input type="text" name="nome" id="config_nome" class="form-control" required placeholder="Ex: Multa por atraso, Juros de mora...">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" id="config_tipo" class="form-select">
                                    <option value="taxa">Taxa</option>
                                    <option value="multa">Multa</option>
                                    <option value="juros">Juros</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Cobrança</label>
                                <select name="tipo_cobranca" id="config_tipo_cobranca" class="form-select" onchange="toggleValorLabel()">
                                    <option value="percentual">Percentual (%)</option>
                                    <option value="fixo">Valor Fixo (Kz)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" id="valor_label">Valor (%)</label>
                            <input type="text" name="valor" id="config_valor" class="form-control" required placeholder="0,00">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Aplicação</label>
                                <select name="aplicacao" id="config_aplicacao" class="form-select">
                                    <option value="mensalidade">Mensalidades</option>
                                    <option value="outros_pagamentos">Outros Pagamentos</option>
                                    <option value="todos">Todos</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Dias de Atraso</label>
                                <input type="number" name="dias_atraso" id="config_dias_atraso" class="form-control" value="0" min="0">
                                <small class="text-muted">Aplicar após X dias de atraso</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" id="config_descricao" class="form-control" rows="2" placeholder="Descrição detalhada..."></textarea>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="ativo" class="form-check-input" id="config_ativo" checked>
                            <label class="form-check-label" for="config_ativo">Ativo</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar</button>
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
    
    function formatarValor(valor) {
        let v = valor.replace(/\D/g, '');
        v = (v / 100).toFixed(2) + '';
        v = v.replace('.', ',');
        v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return v;
    }
    
    function toggleValorLabel() {
        let tipo = $('#config_tipo_cobranca').val();
        if (tipo == 'percentual') {
            $('#valor_label').text('Valor (%)');
            $('#config_valor').attr('placeholder', '0,00');
        } else {
            $('#valor_label').text('Valor Fixo (Kz)');
            $('#config_valor').attr('placeholder', '0,00');
        }
    }
    
    $('#config_valor').on('input', function() {
        $(this).val(formatarValor($(this).val()));
    });
    
    function editarConfig(config) {
        $('#config_id').val(config.id);
        $('#config_nome').val(config.nome);
        $('#config_tipo').val(config.tipo);
        $('#config_tipo_cobranca').val(config.tipo_cobranca);
        if (config.tipo_cobranca == 'percentual') {
            $('#config_valor').val(config.valor.toFixed(1).replace('.', ','));
        } else {
            $('#config_valor').val(config.valor.toFixed(2).replace('.', ','));
        }
        $('#config_aplicacao').val(config.aplicacao);
        $('#config_dias_atraso').val(config.dias_atraso);
        $('#config_descricao').val(config.descricao);
        $('#config_ativo').prop('checked', config.ativo == 1);
        $('#modalConfigTitle').html('<i class="fas fa-edit"></i> Editar Configuração');
        toggleValorLabel();
        new bootstrap.Modal(document.getElementById('modalNovaConfig')).show();
    }
    
    function excluirConfig(id, nome) {
        if(confirm('Tem certeza que deseja excluir a configuração "' + nome + '"?')) {
            $('<form method="POST"><input type="hidden" name="action" value="delete_config"><input type="hidden" name="id" value="' + id + '"></form>').appendTo('body').submit();
        }
    }
    
    function registrarPagamentoTaxa(id, valor) {
        if(confirm('Registrar pagamento da taxa no valor de ' + valor + '?')) {
            alert('Função em desenvolvimento');
        }
    }
    
    $('#modalNovaConfig').on('hidden.bs.modal', function() {
        $('#config_id').val('');
        $('#modalConfigTitle').html('<i class="fas fa-plus-circle"></i> Nova Configuração');
    });
    
    toggleValorLabel();
</script>
</body>
</html>