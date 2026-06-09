<?php
// escola/financeiro/pagamentos_obrigatorio.php - Gestão de Pagamentos Obrigatórios

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
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';

// Inserir novo pagamento obrigatório
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'insert') {
        $tipo_pagamento_id = (int)$_POST['tipo_pagamento_id'];
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $periodo = $_POST['periodo'];
        $data_vencimento = $_POST['data_vencimento'];
        $data_fim_vencimento = !empty($_POST['data_fim_vencimento']) ? $_POST['data_fim_vencimento'] : null;
        $juros_multa = floatval(str_replace(',', '.', $_POST['juros_multa'] ?? '0'));
        $alunos_afetados = $_POST['alunos_afetados'];
        $alunos_ids = isset($_POST['alunos_ids']) ? implode(',', $_POST['alunos_ids']) : null;
        $series_afetadas = isset($_POST['series_afetadas']) ? implode(',', $_POST['series_afetadas']) : null;
        $turmas_afetadas = isset($_POST['turmas_afetadas']) ? implode(',', $_POST['turmas_afetadas']) : null;
        $is_recorrente = isset($_POST['is_recorrente']) ? 1 : 0;
        $mes_referencia = !empty($_POST['mes_referencia']) ? (int)$_POST['mes_referencia'] : null;
        $ano_referencia = !empty($_POST['ano_referencia']) ? (int)$_POST['ano_referencia'] : date('Y');
        
        if ($valor <= 0) {
            $error = "Valor do pagamento inválido.";
        } elseif (empty($nome)) {
            $error = "Nome do pagamento é obrigatório.";
        } else {
            try {
                $sql = "INSERT INTO pagamentos_obrigatorios (
                            escola_id, tipo_pagamento_id, nome, descricao, valor, periodo, 
                            mes_referencia, ano_referencia, data_vencimento, data_fim_vencimento, 
                            juros_multa, alunos_afetados, alunos_ids, series_afetadas, 
                            turmas_afetadas, is_recorrente, ativo, created_at
                        ) VALUES (
                            :escola_id, :tipo_pagamento_id, :nome, :descricao, :valor, :periodo,
                            :mes_referencia, :ano_referencia, :data_vencimento, :data_fim_vencimento,
                            :juros_multa, :alunos_afetados, :alunos_ids, :series_afetadas,
                            :turmas_afetadas, :is_recorrente, 1, NOW()
                        )";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':tipo_pagamento_id' => $tipo_pagamento_id,
                    ':nome' => $nome,
                    ':descricao' => $descricao,
                    ':valor' => $valor,
                    ':periodo' => $periodo,
                    ':mes_referencia' => $mes_referencia,
                    ':ano_referencia' => $ano_referencia,
                    ':data_vencimento' => $data_vencimento,
                    ':data_fim_vencimento' => $data_fim_vencimento,
                    ':juros_multa' => $juros_multa,
                    ':alunos_afetados' => $alunos_afetados,
                    ':alunos_ids' => $alunos_ids,
                    ':series_afetadas' => $series_afetadas,
                    ':turmas_afetadas' => $turmas_afetadas,
                    ':is_recorrente' => $is_recorrente
                ]);
                $success = "Pagamento obrigatório cadastrado com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao cadastrar: " . $e->getMessage();
            }
        }
    }
    
    // Atualizar pagamento obrigatório
    elseif ($_POST['action'] == 'update') {
        $id = (int)$_POST['id'];
        $tipo_pagamento_id = (int)$_POST['tipo_pagamento_id'];
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $periodo = $_POST['periodo'];
        $data_vencimento = $_POST['data_vencimento'];
        $data_fim_vencimento = !empty($_POST['data_fim_vencimento']) ? $_POST['data_fim_vencimento'] : null;
        $juros_multa = floatval(str_replace(',', '.', $_POST['juros_multa'] ?? '0'));
        $alunos_afetados = $_POST['alunos_afetados'];
        $alunos_ids = isset($_POST['alunos_ids']) ? implode(',', $_POST['alunos_ids']) : null;
        $series_afetadas = isset($_POST['series_afetadas']) ? implode(',', $_POST['series_afetadas']) : null;
        $turmas_afetadas = isset($_POST['turmas_afetadas']) ? implode(',', $_POST['turmas_afetadas']) : null;
        $is_recorrente = isset($_POST['is_recorrente']) ? 1 : 0;
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        try {
            $sql = "UPDATE pagamentos_obrigatorios SET 
                        tipo_pagamento_id = :tipo_pagamento_id,
                        nome = :nome,
                        descricao = :descricao,
                        valor = :valor,
                        periodo = :periodo,
                        data_vencimento = :data_vencimento,
                        data_fim_vencimento = :data_fim_vencimento,
                        juros_multa = :juros_multa,
                        alunos_afetados = :alunos_afetados,
                        alunos_ids = :alunos_ids,
                        series_afetadas = :series_afetadas,
                        turmas_afetadas = :turmas_afetadas,
                        is_recorrente = :is_recorrente,
                        ativo = :ativo,
                        updated_at = NOW()
                    WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':escola_id' => $escola_id,
                ':tipo_pagamento_id' => $tipo_pagamento_id,
                ':nome' => $nome,
                ':descricao' => $descricao,
                ':valor' => $valor,
                ':periodo' => $periodo,
                ':data_vencimento' => $data_vencimento,
                ':data_fim_vencimento' => $data_fim_vencimento,
                ':juros_multa' => $juros_multa,
                ':alunos_afetados' => $alunos_afetados,
                ':alunos_ids' => $alunos_ids,
                ':series_afetadas' => $series_afetadas,
                ':turmas_afetadas' => $turmas_afetadas,
                ':is_recorrente' => $is_recorrente,
                ':ativo' => $ativo
            ]);
            $success = "Pagamento obrigatório atualizado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao atualizar: " . $e->getMessage();
        }
    }
    
    // Excluir pagamento obrigatório
    elseif ($_POST['action'] == 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            $sql = "DELETE FROM pagamentos_obrigatorios WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
            $success = "Pagamento obrigatório excluído com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao excluir: " . $e->getMessage();
        }
    }
    
    // Gerar lançamentos para os alunos
    elseif ($_POST['action'] == 'gerar_lancamentos') {
        $id = (int)$_POST['id'];
        
        try {
            // Buscar dados do pagamento obrigatório
            $sql_obrigatorio = "SELECT * FROM pagamentos_obrigatorios WHERE id = :id AND escola_id = :escola_id";
            $stmt_obrigatorio = $conn->prepare($sql_obrigatorio);
            $stmt_obrigatorio->execute([':id' => $id, ':escola_id' => $escola_id]);
            $obrigatorio = $stmt_obrigatorio->fetch(PDO::FETCH_ASSOC);
            
            if (!$obrigatorio) {
                throw new Exception("Pagamento obrigatório não encontrado.");
            }
            
            // Buscar alunos afetados
            $sql_alunos = "SELECT id, nome, matricula FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo'";
            $params = [':escola_id' => $escola_id];
            
            if ($obrigatorio['alunos_afetados'] == 'especificos' && !empty($obrigatorio['alunos_ids'])) {
                $alunos_ids = explode(',', $obrigatorio['alunos_ids']);
                $placeholders = implode(',', array_fill(0, count($alunos_ids), '?'));
                $sql_alunos .= " AND id IN ($placeholders)";
                $params = array_merge([':escola_id' => $escola_id], $alunos_ids);
            }
            
            $stmt_alunos = $conn->prepare($sql_alunos);
            $stmt_alunos->execute($params);
            $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
            
            $total_gerados = 0;
            
            foreach ($alunos as $aluno) {
                // Verificar se já existe lançamento para este período
                $sql_check = "SELECT id FROM pagamentos_obrigatorios_lancamentos 
                              WHERE pagamento_obrigatorio_id = :pagamento_id 
                              AND aluno_id = :aluno_id 
                              AND mes_referencia = :mes 
                              AND ano_referencia = :ano";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([
                    ':pagamento_id' => $id,
                    ':aluno_id' => $aluno['id'],
                    ':mes' => $obrigatorio['mes_referencia'] ?? date('m'),
                    ':ano' => $obrigatorio['ano_referencia'] ?? date('Y')
                ]);
                
                if (!$stmt_check->fetch()) {
                    $sql_insert = "INSERT INTO pagamentos_obrigatorios_lancamentos (
                                        escola_id, pagamento_obrigatorio_id, aluno_id, valor, 
                                        mes_referencia, ano_referencia, data_vencimento, status
                                    ) VALUES (
                                        :escola_id, :pagamento_id, :aluno_id, :valor,
                                        :mes, :ano, :data_vencimento, 'pendente'
                                    )";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':escola_id' => $escola_id,
                        ':pagamento_id' => $id,
                        ':aluno_id' => $aluno['id'],
                        ':valor' => $obrigatorio['valor'],
                        ':mes' => $obrigatorio['mes_referencia'] ?? date('m'),
                        ':ano' => $obrigatorio['ano_referencia'] ?? date('Y'),
                        ':data_vencimento' => $obrigatorio['data_vencimento']
                    ]);
                    $total_gerados++;
                }
            }
            
            $success = "Lançamentos gerados com sucesso! Total: $total_gerados alunos.";
        } catch (Exception $e) {
            $error = "Erro ao gerar lançamentos: " . $e->getMessage();
        }
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar tipos de pagamento
$sql_tipos = "SELECT id, nome, icone, cor FROM tipos_pagamento WHERE ativo = 1 ORDER BY ordem ASC";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute();
$tipos_pagamento = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// Buscar pagamentos obrigatórios
$sql_obrigatorios = "SELECT po.*, tp.nome as tipo_nome, tp.icone, tp.cor,
                     (SELECT COUNT(*) FROM pagamentos_obrigatorios_lancamentos WHERE pagamento_obrigatorio_id = po.id) as total_lancamentos
                     FROM pagamentos_obrigatorios po
                     LEFT JOIN tipos_pagamento tp ON tp.id = po.tipo_pagamento_id
                     WHERE po.escola_id = :escola_id
                     ORDER BY po.created_at DESC";
$stmt_obrigatorios = $conn->prepare($sql_obrigatorios);
$stmt_obrigatorios->execute([':escola_id' => $escola_id]);
$pagamentos_obrigatorios = $stmt_obrigatorios->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos para seleção
$sql_alunos = "SELECT id, nome, matricula FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar séries (tabela correta - pode ser 'series' ou 'classes')
try {
    $sql_series = "SELECT id, nome FROM series WHERE escola_id = :escola_id ORDER BY nome ASC";
    $stmt_series = $conn->prepare($sql_series);
    $stmt_series->execute([':escola_id' => $escola_id]);
    $series = $stmt_series->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Se não existir tabela series, tenta classes
    try {
        $sql_series = "SELECT id, nome FROM classes WHERE escola_id = :escola_id ORDER BY nome ASC";
        $stmt_series = $conn->prepare($sql_series);
        $stmt_series->execute([':escola_id' => $escola_id]);
        $series = $stmt_series->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $series = [];
    }
}

// Buscar turmas
$sql_turmas = "SELECT id, nome FROM turmas WHERE escola_id = :escola_id ORDER BY nome ASC";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getPeriodoLabel($periodo) {
    $periodos = [
        'unico' => 'Único',
        'mensal' => 'Mensal',
        'trimestral' => 'Trimestral',
        'semestral' => 'Semestral',
        'anual' => 'Anual'
    ];
    return $periodos[$periodo] ?? $periodo;
}

function getAlunosAfetadosLabel($tipo) {
    $tipos = [
        'todos' => 'Todos os alunos',
        'matriculados' => 'Alunos matriculados',
        'especificos' => 'Alunos específicos'
    ];
    return $tipos[$tipo] ?? $tipo;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamentos Obrigatórios | Financeiro | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        
        .badge-ativo { background: #28a745; color: white; padding: 3px 8px; border-radius: 20px; font-size: 0.7rem; }
        .badge-inativo { background: #dc3545; color: white; padding: 3px 8px; border-radius: 20px; font-size: 0.7rem; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .select2-container .select2-selection--multiple { min-height: 38px; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-exclamation-circle"></i> Pagamentos Obrigatórios</h2>
                <p class="text-muted">Configurar pagamentos obrigatórios que serão cobrados dos alunos</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPagamento">
                    <i class="fas fa-plus"></i> Novo Pagamento Obrigatório
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
        
        <!-- Lista de Pagamentos Obrigatórios -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Pagamentos Obrigatórios Cadastrados</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Tipo</th>
                                <th>Nome</th>
                                <th>Valor</th>
                                <th>Período</th>
                                <th>Vencimento</th>
                                <th>Alunos</th>
                                <th>Lançamentos</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagamentos_obrigatorios as $pg): ?>
                            <tr>
                                <td><?php echo $pg['id']; ?></td>
                                <td>>
                                    <i class="<?php echo $pg['icone'] ?? 'fas fa-tag'; ?>" style="color: <?php echo $pg['cor'] ?? '#006B3E'; ?>;"></i>
                                    <?php echo htmlspecialchars($pg['tipo_nome'] ?? '-'); ?>
                                 </td>
                                <td><strong><?php echo htmlspecialchars($pg['nome']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars(substr($pg['descricao'] ?? '', 0, 50)); ?></small></td>
                                <td><?php echo formatarMoeda($pg['valor']); ?></td>
                                <td><?php echo getPeriodoLabel($pg['periodo']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($pg['data_vencimento'])); ?></td>
                                <td><?php echo getAlunosAfetadosLabel($pg['alunos_afetados']); ?></td>
                                <td>
                                    <span class="badge bg-info"><?php echo $pg['total_lancamentos']; ?></span>
                                    <button class="btn btn-sm btn-warning" onclick="gerarLancamentos(<?php echo $pg['id']; ?>)">
                                        <i class="fas fa-sync-alt"></i> Gerar
                                    </button>
                                 </td>
                                <td>
                                    <?php if ($pg['ativo']): ?>
                                        <span class="badge-ativo">Ativo</span>
                                    <?php else: ?>
                                        <span class="badge-inativo">Inativo</span>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editarPagamento(<?php echo htmlspecialchars(json_encode($pg)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="excluirPagamento(<?php echo $pg['id']; ?>, '<?php echo htmlspecialchars($pg['nome']); ?>')">
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
    </div>
    
    <!-- Modal Novo Pagamento Obrigatório -->
    <div class="modal fade" id="modalNovoPagamento" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Novo Pagamento Obrigatório</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Pagamento <span class="text-danger">*</span></label>
                                <select name="tipo_pagamento_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($tipos_pagamento as $tipo): ?>
                                    <option value="<?php echo $tipo['id']; ?>"><?php echo $tipo['nome']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome do Pagamento <span class="text-danger">*</span></label>
                                <input type="text" name="nome" class="form-control" required placeholder="Ex: Taxa de Matrícula 2024">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2" placeholder="Descrição detalhada do pagamento"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Valor <span class="text-danger">*</span></label>
                                <input type="text" name="valor" id="valor" class="form-control" required placeholder="0,00">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Período</label>
                                <select name="periodo" class="form-select">
                                    <option value="unico">Único</option>
                                    <option value="mensal">Mensal</option>
                                    <option value="trimestral">Trimestral</option>
                                    <option value="semestral">Semestral</option>
                                    <option value="anual">Anual</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Recorrente?</label>
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="is_recorrente" class="form-check-input" id="is_recorrente" checked>
                                    <label class="form-check-label" for="is_recorrente">Sim, repetir automaticamente</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Vencimento <span class="text-danger">*</span></label>
                                <input type="date" name="data_vencimento" id="data_vencimento" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data Final (sem multa)</label>
                                <input type="date" name="data_fim_vencimento" class="form-control">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Juros/Multa (%)</label>
                                <input type="text" name="juros_multa" id="juros_multa" class="form-control" placeholder="0,00" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mês Referência</label>
                                <select name="mes_referencia" class="form-select">
                                    <option value="">Selecione...</option>
                                    <?php for($i=1; $i<=12; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo date('F', mktime(0,0,0,$i,1)); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Alunos Afetados</label>
                                <select name="alunos_afetados" id="alunos_afetados" class="form-select" onchange="toggleAlunosSelecao()">
                                    <option value="todos">Todos os alunos</option>
                                    <option value="matriculados">Alunos matriculados</option>
                                    <option value="especificos">Alunos específicos</option>
                                </select>
                            </div>
                        </div>
                        
                        <div id="div_alunos_especificos" style="display:none;">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Selecionar Alunos</label>
                                    <select name="alunos_ids[]" id="select_alunos" class="form-select select2-alunos" multiple>
                                        <?php foreach ($alunos as $aluno): ?>
                                        <option value="<?php echo $aluno['id']; ?>"><?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div id="div_series_turmas" style="display:none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Séries Específicas</label>
                                    <select name="series_afetadas[]" id="select_series" class="form-select select2-series" multiple>
                                        <?php if (!empty($series)): ?>
                                            <?php foreach ($series as $serie): ?>
                                            <option value="<?php echo $serie['id']; ?>"><?php echo htmlspecialchars($serie['nome']); ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>Nenhuma série cadastrada</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Turmas Específicas</label>
                                    <select name="turmas_afetadas[]" id="select_turmas" class="form-select select2-turmas" multiple>
                                        <?php if (!empty($turmas)): ?>
                                            <?php foreach ($turmas as $turma): ?>
                                            <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>Nenhuma turma cadastrada</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Formatar valor
        $('#valor, #juros_multa').on('input', function() {
            let v = $(this).val().replace(/\D/g, '');
            v = (v / 100).toFixed(2).replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            $(this).val(v);
        });
        
        // Inicializar Select2
        $('.select2-alunos').select2({
            theme: 'bootstrap-5',
            placeholder: 'Selecione os alunos',
            allowClear: true,
            dropdownParent: $('#modalNovoPagamento')
        });
        
        $('.select2-series').select2({
            theme: 'bootstrap-5',
            placeholder: 'Selecione as séries',
            allowClear: true,
            dropdownParent: $('#modalNovoPagamento')
        });
        
        $('.select2-turmas').select2({
            theme: 'bootstrap-5',
            placeholder: 'Selecione as turmas',
            allowClear: true,
            dropdownParent: $('#modalNovoPagamento')
        });
        
        function toggleAlunosSelecao() {
            let tipo = $('#alunos_afetados').val();
            $('#div_alunos_especificos').toggle(tipo === 'especificos');
            $('#div_series_turmas').toggle(tipo === 'matriculados');
            
            // Atualizar o Select2 quando o modal é mostrado
            if (tipo === 'especificos') {
                setTimeout(function() {
                    $('.select2-alunos').select2({
                        dropdownParent: $('#modalNovoPagamento')
                    });
                }, 100);
            }
            if (tipo === 'matriculados') {
                setTimeout(function() {
                    $('.select2-series, .select2-turmas').select2({
                        dropdownParent: $('#modalNovoPagamento')
                    });
                }, 100);
            }
        }
        
        function gerarLancamentos(id) {
            if(confirm('Deseja gerar os lançamentos para este pagamento obrigatório?')) {
                $('<form method="POST">' +
                    '<input type="hidden" name="action" value="gerar_lancamentos">' +
                    '<input type="hidden" name="id" value="' + id + '">' +
                    '</form>').appendTo('body').submit();
            }
        }
        
        function editarPagamento(pg) {
            alert('Função de edição em desenvolvimento');
        }
        
        function excluirPagamento(id, nome) {
            if(confirm('Tem certeza que deseja excluir o pagamento "' + nome + '"? Esta ação não pode ser desfeita.')) {
                $('<form method="POST">' +
                    '<input type="hidden" name="action" value="delete">' +
                    '<input type="hidden" name="id" value="' + id + '">' +
                    '</form>').appendTo('body').submit();
            }
        }
        
        // Setar data de vencimento padrão para dia 10 do próximo mês
        let dataPadrao = new Date();
        dataPadrao.setMonth(dataPadrao.getMonth() + 1);
        dataPadrao.setDate(10);
        $('#data_vencimento').val(dataPadrao.toISOString().split('T')[0]);
        
        // Inicializar
        toggleAlunosSelecao();
    </script>
</body>
</html>