<?php
// escola/financeiro/outros_pagamentos.php - Gestão de Outros Pagamentos

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

// Inserir novo pagamento (Individual ou em Massa)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'insert') {
        $tipo_pagamento_id = (int)$_POST['tipo_pagamento_id'];
        $tipo_selecao = $_POST['tipo_selecao']; // 'individual' ou 'turma'
        $turma_id = !empty($_POST['turma_id']) ? (int)$_POST['turma_id'] : null;
        $aluno_id = !empty($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : null;
        $ano_letivo_id = (int)$_POST['ano_letivo_id'];
        $mes_referencia = (int)$_POST['mes_referencia'];
        $ano_referencia = (int)$_POST['ano_referencia'];
        $valor_total = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor_total'] ?? '0')));
        $desconto = floatval(str_replace(',', '.', str_replace('.', '', $_POST['desconto'] ?? '0')));
        $multa = floatval(str_replace(',', '.', str_replace('.', '', $_POST['multa'] ?? '0')));
        $juros = floatval(str_replace(',', '.', str_replace('.', '', $_POST['juros'] ?? '0')));
        $data_vencimento = $_POST['data_vencimento'];
        $observacoes = trim($_POST['observacoes']);
        
        if ($valor_total <= 0) {
            $error = "Valor total do pagamento inválido.";
        } elseif ($tipo_selecao == 'individual' && $aluno_id <= 0) {
            $error = "Selecione um aluno.";
        } elseif ($tipo_selecao == 'turma' && $turma_id <= 0) {
            $error = "Selecione uma turma.";
        } elseif ($tipo_pagamento_id <= 0) {
            $error = "Selecione um tipo de pagamento.";
        } else {
            try {
                $conn->beginTransaction();
                $total_gerados = 0;
                
                // Buscar alunos baseado no tipo de seleção
                if ($tipo_selecao == 'individual') {
                    $alunos = [[ 'id' => $aluno_id ]];
                } else {
                    // Buscar todos os alunos da turma
                   $sql_alunos = "SELECT DISTINCT e.id, e.nome, e.matricula 
               FROM estudantes e
               INNER JOIN matriculas m ON m.estudante_id = e.id
               WHERE e.escola_id = :escola_id 
               AND m.turma_id = :turma_id 
               AND m.ano_letivo = :ano_letivo_id
               AND e.status = 'ativo'
               ORDER BY e.nome ASC";
                    $stmt_alunos = $conn->prepare($sql_alunos);
                    $stmt_alunos->execute([
                        ':escola_id' => $escola_id,
                        ':turma_id' => $turma_id,
                        ':ano_letivo_id' => $ano_letivo_id
                    ]);
                    
                    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($alunos)) {
                        throw new Exception("Nenhum aluno encontrado nesta turma.");
                    }
                }
                
                // Gerar pagamento para cada aluno
                foreach ($alunos as $aluno) {
                    $sql = "INSERT INTO outros_pagamentos (
                                escola_id, tipo_pagamento_id, aluno_id, turma_id, ano_letivo_id,
                                mes_referencia, ano_referencia, valor_total, valor_pago, desconto,
                                multa, juros, data_vencimento, status, observacoes, created_at
                            ) VALUES (
                                :escola_id, :tipo_pagamento_id, :aluno_id, :turma_id, :ano_letivo_id,
                                :mes_referencia, :ano_referencia, :valor_total, 0, :desconto,
                                :multa, :juros, :data_vencimento, 'pendente', :observacoes, NOW()
                            )";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':escola_id' => $escola_id,
                        ':tipo_pagamento_id' => $tipo_pagamento_id,
                        ':aluno_id' => $aluno['id'],
                        ':turma_id' => $turma_id,
                        ':ano_letivo_id' => $ano_letivo_id,
                        ':mes_referencia' => $mes_referencia,
                        ':ano_referencia' => $ano_referencia,
                        ':valor_total' => $valor_total,
                        ':desconto' => $desconto,
                        ':multa' => $multa,
                        ':juros' => $juros,
                        ':data_vencimento' => $data_vencimento,
                        ':observacoes' => $observacoes
                    ]);
                    $total_gerados++;
                }
                
                $conn->commit();
                
                if ($tipo_selecao == 'individual') {
                    $success = "Pagamento registrado com sucesso!";
                } else {
                    $success = "Pagamento gerado em massa para $total_gerados alunos da turma!";
                }
                
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Erro ao registrar: " . $e->getMessage();
            }
        }
    }
    
    // Registrar pagamento (dar baixa)
    elseif ($_POST['action'] == 'registrar_pagamento') {
        $id = (int)$_POST['id'];
        $valor_pago = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor_pago'] ?? '0')));
        $data_pagamento = $_POST['data_pagamento'];
        $metodo_pagamento = $_POST['metodo_pagamento'];
        $observacoes_pagamento = trim($_POST['observacoes_pagamento']);
        
        try {
            $conn->beginTransaction();
            
            // Buscar dados atuais
            $sql_old = "SELECT * FROM outros_pagamentos WHERE id = :id AND escola_id = :escola_id";
            $stmt_old = $conn->prepare($sql_old);
            $stmt_old->execute([':id' => $id, ':escola_id' => $escola_id]);
            $pagamento = $stmt_old->fetch(PDO::FETCH_ASSOC);
            
            if (!$pagamento) {
                throw new Exception("Pagamento não encontrado.");
            }
            
            $novo_valor_pago = ($pagamento['valor_pago'] ?? 0) + $valor_pago;
            $novo_status = ($novo_valor_pago >= $pagamento['valor_total']) ? 'pago' : 'parcial';
            
            // Atualizar outros_pagamentos
            $sql_update = "UPDATE outros_pagamentos 
                           SET valor_pago = :valor_pago, 
                               status = :status,
                               data_pagamento = :data_pagamento,
                               updated_at = NOW()
                           WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([
                ':valor_pago' => $novo_valor_pago,
                ':status' => $novo_status,
                ':data_pagamento' => $data_pagamento,
                ':id' => $id
            ]);
            
            // Gerar número da fatura e comprovativo
            $numero_fatura = gerarNumeroFatura($conn, $escola_id);
            $comprovativo_numero = gerarNumeroComprovativo($conn, $escola_id);
            
            // Buscar nome do tipo de pagamento
            $tipo_nome = getTipoPagamentoNome($conn, $pagamento['tipo_pagamento_id']);
            
            // Registrar na tabela pagamentos
            $sql_pagamento = "INSERT INTO pagamentos (
                                escola_id, assinatura_id, tipo_pagamento_id, tipo_pagamento, 
                                valor, metodo_pagamento, referente, observacoes, 
                                data_pagamento, usuario_id, status, numero_fatura, 
                                comprovativo_numero, created_at
                            ) VALUES (
                                :escola_id, :aluno_id, :tipo_pagamento_id, :tipo_pagamento,
                                :valor, :metodo_pagamento, :referente, :observacoes,
                                :data_pagamento, :usuario_id, 'confirmado', :numero_fatura,
                                :comprovativo_numero, NOW()
                            )";
            $stmt_pagamento = $conn->prepare($sql_pagamento);
            $stmt_pagamento->execute([
                ':escola_id' => $escola_id,
                ':aluno_id' => $pagamento['aluno_id'],
                ':tipo_pagamento_id' => $pagamento['tipo_pagamento_id'],
                ':tipo_pagamento' => $tipo_nome,
                ':valor' => $valor_pago,
                ':metodo_pagamento' => $metodo_pagamento,
                ':referente' => "Pagamento: " . $tipo_nome,
                ':observacoes' => $observacoes_pagamento,
                ':data_pagamento' => $data_pagamento,
                ':usuario_id' => $usuario_id,
                ':numero_fatura' => $numero_fatura,
                ':comprovativo_numero' => $comprovativo_numero
            ]);
            $pagamento_id = $conn->lastInsertId();
            
            // Atualizar referência do pagamento
            $sql_ref = "UPDATE outros_pagamentos SET pagamento_id = :pagamento_id WHERE id = :id";
            $stmt_ref = $conn->prepare($sql_ref);
            $stmt_ref->execute([':pagamento_id' => $pagamento_id, ':id' => $id]);
            
            // Registrar no caixa
            $sql_caixa = "INSERT INTO caixa (
                            escola_id, tipo, categoria, descricao, valor, 
                            metodo_pagamento, referencia, usuario_id, pagamento_id, 
                            data_movimento, status, created_at
                        ) VALUES (
                            :escola_id, 'entrada', :categoria, :descricao, :valor,
                            :metodo_pagamento, :referencia, :usuario_id, :pagamento_id,
                            :data_movimento, 'ativo', NOW()
                        )";
            $stmt_caixa = $conn->prepare($sql_caixa);
            $stmt_caixa->execute([
                ':escola_id' => $escola_id,
                ':categoria' => $tipo_nome,
                ':descricao' => "Pagamento - " . $tipo_nome,
                ':valor' => $valor_pago,
                ':metodo_pagamento' => $metodo_pagamento,
                ':referencia' => $numero_fatura,
                ':usuario_id' => $usuario_id,
                ':pagamento_id' => $pagamento_id,
                ':data_movimento' => $data_pagamento
            ]);
            
            $conn->commit();
            
            $success = "Pagamento registrado com sucesso! Fatura Nº: $numero_fatura";
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Erro ao registrar pagamento: " . $e->getMessage();
        }
    }
    
    // Cancelar pagamento
    elseif ($_POST['action'] == 'cancelar') {
        $id = (int)$_POST['id'];
        
        try {
            $sql = "UPDATE outros_pagamentos SET status = 'cancelado', updated_at = NOW() 
                    WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
            $success = "Pagamento cancelado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao cancelar: " . $e->getMessage();
        }
    }
    
    // Excluir pagamento
    elseif ($_POST['action'] == 'delete') {
        $id = (int)$_POST['id'];
        
        try {
            $sql = "DELETE FROM outros_pagamentos WHERE id = :id AND escola_id = :escola_id AND status = 'pendente'";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
            $success = "Pagamento excluído com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao excluir: " . $e->getMessage();
        }
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function getTipoPagamentoNome($conn, $tipo_id) {
    $sql = "SELECT nome FROM tipos_pagamento WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $tipo_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['nome'] ?? 'Outro';
}

function gerarNumeroFatura($conn, $escola_id) {
    $ano = date('Y');
    $sql = "SELECT COUNT(*) as total FROM pagamentos WHERE escola_id = :escola_id AND YEAR(created_at) = :ano";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    return "FT/" . $ano . "/" . str_pad(($count['total'] + 1), 5, '0', STR_PAD_LEFT);
}

function gerarNumeroComprovativo($conn, $escola_id) {
    $ano = date('Y');
    $sql = "SELECT COUNT(*) as total FROM pagamentos WHERE escola_id = :escola_id AND YEAR(created_at) = :ano";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    return "C" . str_pad(($count['total'] + 1), 8, '0', STR_PAD_LEFT) . "/" . $ano;
}

function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pendente': return '<span class="badge bg-warning text-dark">Pendente</span>';
        case 'parcial': return '<span class="badge bg-info">Parcial</span>';
        case 'pago': return '<span class="badge bg-success">Pago</span>';
        case 'cancelado': return '<span class="badge bg-danger">Cancelado</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar tipos de pagamento
$sql_tipos = "SELECT id, nome, icone, cor FROM tipos_pagamento WHERE ativo = 1 and nome!='Mensalidade' ORDER BY ordem ASC";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute();
$tipos_pagamento = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos
$sql_alunos = "SELECT id, nome, matricula FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas
$sql_turmas = "SELECT id, nome FROM turmas WHERE escola_id = :escola_id ORDER BY nome ASC";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar ano letivo ativo
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id AND ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo_ativo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);

// Listar outros pagamentos
$sql_pagamentos = "SELECT op.*, 
                          e.nome as aluno_nome, e.matricula,
                          t.nome as turma_nome,
                          tp.nome as tipo_nome, tp.icone, tp.cor,
                          al.ano as ano_letivo
                   FROM outros_pagamentos op
                   LEFT JOIN estudantes e ON e.id = op.aluno_id
                   LEFT JOIN turmas t ON t.id = op.turma_id
                   LEFT JOIN tipos_pagamento tp ON tp.id = op.tipo_pagamento_id
                   LEFT JOIN ano_letivo al ON al.id = op.ano_letivo_id
                   WHERE op.escola_id = :escola_id
                   ORDER BY op.data_vencimento ASC, op.created_at DESC";
$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute([':escola_id' => $escola_id]);
$outros_pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================

// Estatísticas gerais
$sql_stats = "SELECT 
                COUNT(*) as total_pagamentos,
                SUM(valor_total) as total_valor,
                SUM(valor_pago) as total_pago,
                SUM(valor_total - valor_pago) as total_devedor,
                COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
                COUNT(CASE WHEN status = 'parcial' THEN 1 END) as parciais,
                COUNT(CASE WHEN status = 'pago' THEN 1 END) as pagos,
                COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelados
              FROM outros_pagamentos 
              WHERE escola_id = :escola_id";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([':escola_id' => $escola_id]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Estatísticas por tipo de pagamento
$sql_stats_tipo = "SELECT 
                      tp.nome as tipo_nome,
                      COUNT(op.id) as quantidade,
                      SUM(op.valor_total) as total_valor,
                      SUM(op.valor_pago) as total_pago,
                      SUM(op.valor_total - op.valor_pago) as total_devedor
                   FROM outros_pagamentos op
                   LEFT JOIN tipos_pagamento tp ON tp.id = op.tipo_pagamento_id
                   WHERE op.escola_id = :escola_id
                   GROUP BY op.tipo_pagamento_id
                   ORDER BY total_valor DESC";
$stmt_stats_tipo = $conn->prepare($sql_stats_tipo);
$stmt_stats_tipo->execute([':escola_id' => $escola_id]);
$stats_por_tipo = $stmt_stats_tipo->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas por turma
$sql_stats_turma = "SELECT 
                       t.nome as turma_nome,
                       COUNT(op.id) as quantidade,
                       SUM(op.valor_total) as total_valor,
                       SUM(op.valor_pago) as total_pago,
                       SUM(op.valor_total - op.valor_pago) as total_devedor
                    FROM outros_pagamentos op
                    LEFT JOIN turmas t ON t.id = op.turma_id
                    WHERE op.escola_id = :escola_id AND op.turma_id IS NOT NULL
                    GROUP BY op.turma_id
                    ORDER BY total_devedor DESC
                    LIMIT 10";
$stmt_stats_turma = $conn->prepare($sql_stats_turma);
$stmt_stats_turma->execute([':escola_id' => $escola_id]);
$stats_por_turma = $stmt_stats_turma->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outros Pagamentos | Financeiro | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; position: relative; overflow: hidden; }
        .stat-card h3 { font-size: 2rem; margin: 0; font-weight: bold; }
        .stat-card p { margin: 0; opacity: 0.9; }
        .stat-card .icon { font-size: 3rem; opacity: 0.3; position: absolute; right: 20px; top: 20px; }
        
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .pendente { border-left: 4px solid #ffc107; }
        .parcial { border-left: 4px solid #17a2b8; }
        .pago { border-left: 4px solid #28a745; }
        .cancelado { border-left: 4px solid #dc3545; }
        
        .select2-container .select2-selection--single { height: 38px; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 38px; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
        
        .tipo-card { cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
        .tipo-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .tipo-card.selected { border-color: #006B3E; background: #e8f5e9; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-hand-holding-usd"></i> Outros Pagamentos</h2>
                <p class="text-muted">Gerenciar pagamentos avulsos, taxas e serviços extras</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPagamento">
                    <i class="fas fa-plus"></i> Novo Pagamento
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
        
        <!-- ESTATÍSTICAS -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <p>Total de Pagamentos</p>
                    <h3><?php echo number_format($stats['total_pagamentos'] ?? 0); ?></h3>
                    <small>Lançamentos realizados</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    <div class="icon"><i class="fas fa-money-bill"></i></div>
                    <p>Total Arrecadado</p>
                    <h3><?php echo formatarMoeda($stats['total_pago'] ?? 0); ?></h3>
                    <small>Valor já pago</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%);">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <p>Em Aberto</p>
                    <h3><?php echo formatarMoeda($stats['total_devedor'] ?? 0); ?></h3>
                    <small><?php echo ($stats['pendentes'] ?? 0) + ($stats['parciais'] ?? 0); ?> pagamentos pendentes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #fd7e14 0%, #ffc107 100%);">
                    <div class="icon"><i class="fas fa-chart-pie"></i></div>
                    <p>Taxa de Adimplência</p>
                    <h3><?php 
                        $total_geral = ($stats['total_valor'] ?? 0);
                        $total_pago = ($stats['total_pago'] ?? 0);
                        $taxa = $total_geral > 0 ? ($total_pago / $total_geral) * 100 : 0;
                        echo number_format($taxa, 1); ?>%
                    </h3>
                    <small>Do valor total arrecadado</small>
                </div>
            </div>
        </div>
        
        <!-- GRÁFICOS -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Distribuição por Tipo de Pagamento
                    </div>
                    <div class="card-body">
                        <canvas id="tipoChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php foreach ($stats_por_tipo as $tipo): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><?php echo htmlspecialchars($tipo['tipo_nome']); ?></span>
                                <span class="fw-bold"><?php echo formatarMoeda($tipo['total_valor']); ?></span>
                                <span class="text-muted">(<?php echo $tipo['quantidade']; ?>x)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-bar"></i> Top 10 Turmas com Mais Débitos
                    </div>
                    <div class="card-body">
                        <canvas id="turmaChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php foreach ($stats_por_turma as $turma): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><?php echo htmlspecialchars($turma['turma_nome']); ?></span>
                                <span class="text-danger fw-bold"><?php echo formatarMoeda($turma['total_devedor']); ?></span>
                            </div>
                            <div class="progress mb-2" style="height: 5px;">
                                <div class="progress-bar bg-danger" style="width: <?php echo ($turma['total_devedor'] / max($stats['total_devedor'], 1)) * 100; ?>%"></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Lista de Pagamentos -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Pagamentos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Aluno</th>
                                <th>Tipo</th>
                                <th>Valor Total</th>
                                <th>Valor Pago</th>
                                <th>Saldo</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outros_pagamentos as $pg): ?>
                            <tr class="<?php echo $pg['status']; ?>">
                                <td><?php echo $pg['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($pg['aluno_nome']); ?></strong><br>
                                    <small class="text-muted">Mat: <?php echo $pg['matricula']; ?></small>
                                    <?php if ($pg['turma_nome']): ?>
                                    <br><small><?php echo htmlspecialchars($pg['turma_nome']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <i class="<?php echo $pg['icone'] ?? 'fas fa-tag'; ?>" style="color: <?php echo $pg['cor'] ?? '#006B3E'; ?>;"></i>
                                    <?php echo htmlspecialchars($pg['tipo_nome']); ?>
                                    <br><small><?php echo $pg['mes_referencia'] . '/' . $pg['ano_referencia']; ?></small>
                                </td>
                                <td><?php echo formatarMoeda($pg['valor_total']); ?></td>
                                <td><?php echo formatarMoeda($pg['valor_pago']); ?></td>
                                <td class="<?php echo ($pg['valor_total'] - $pg['valor_pago']) > 0 ? 'text-danger fw-bold' : 'text-success'; ?>">
                                    <?php echo formatarMoeda($pg['valor_total'] - $pg['valor_pago']); ?>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($pg['data_vencimento'])); ?></td>
                                <td><?php echo getStatusBadge($pg['status']); ?></td>
                                <td>
                                    <?php if ($pg['status'] == 'pendente' || $pg['status'] == 'parcial'): ?>
                                    <button class="btn btn-sm btn-success" onclick="abrirModalPagamento(<?php echo htmlspecialchars(json_encode($pg)); ?>)">
                                        <i class="fas fa-money-bill"></i> Pagar
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="cancelarPagamento(<?php echo $pg['id']; ?>)">
                                        <i class="fas fa-times"></i> Cancelar
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($pg['status'] == 'pendente'): ?>
                                    <button class="btn btn-sm btn-danger" onclick="excluirPagamento(<?php echo $pg['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Pagamento -->
    <div class="modal fade" id="modalNovoPagamento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Novo Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formNovoPagamento">
                    <input type="hidden" name="action" value="insert">
                    <div class="modal-body">
                        <!-- Tipo de Seleção: Individual ou Turma -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Tipo de Lançamento <span class="text-danger">*</span></label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="tipo_selecao" id="tipo_individual" value="individual" checked autocomplete="off">
                                    <label class="btn btn-outline-primary" for="tipo_individual">
                                        <i class="fas fa-user"></i> Individual (Apenas um aluno)
                                    </label>
                                    <input type="radio" class="btn-check" name="tipo_selecao" id="tipo_turma" value="turma" autocomplete="off">
                                    <label class="btn btn-outline-primary" for="tipo_turma">
                                        <i class="fas fa-users"></i> Em Massa (Turma inteira)
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Seleção Individual -->
                        <div id="div_individual">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Aluno <span class="text-danger">*</span></label>
                                    <select name="aluno_id" class="form-select select2-aluno" style="width: 100%;">
                                        <option value="">Selecione um aluno...</option>
                                        <?php foreach ($alunos as $aluno): ?>
                                        <option value="<?php echo $aluno['id']; ?>"><?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Seleção em Massa (Turma) -->
                        <div id="div_turma" style="display:none;">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Turma <span class="text-danger">*</span></label>
                                    <select name="turma_id" class="form-select select2-turma" style="width: 100%;">
                                        <option value="">Selecione uma turma...</option>
                                        <?php foreach ($turmas as $turma): ?>
                                        <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">O pagamento será gerado para TODOS os alunos ativos desta turma</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tipo de Pagamento -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Tipo de Pagamento <span class="text-danger">*</span></label>
                                <div class="row">
                                    <?php foreach ($tipos_pagamento as $tipo): ?>
                                    <div class="col-md-3 mb-2">
                                        <div class="card tipo-card" data-tipo-id="<?php echo $tipo['id']; ?>" style="border-left: 4px solid <?php echo $tipo['cor']; ?>; cursor: pointer;">
                                            <div class="card-body p-2 text-center">
                                                <i class="<?php echo $tipo['icone']; ?>" style="font-size: 1.2rem; color: <?php echo $tipo['cor']; ?>;"></i>
                                                <div class="small fw-bold"><?php echo $tipo['nome']; ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="tipo_pagamento_id" id="tipo_pagamento_id" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ano Letivo <span class="text-danger">*</span></label>
                                <select name="ano_letivo_id" class="form-select" required>
                                    <option value="<?php echo $ano_letivo_ativo['id'] ?? ''; ?>">
                                        <?php echo $ano_letivo_ativo['nome'] ?? 'Ano Letivo Atual'; ?>
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Vencimento <span class="text-danger">*</span></label>
                                <input type="date" name="data_vencimento" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mês Referência</label>
                                <select name="mes_referencia" class="form-select">
                                    <?php for($i=1; $i<=12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == date('m') ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0,0,0,$i,1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Ano Referência</label>
                                <select name="ano_referencia" class="form-select">
                                    <?php for($i = date('Y')-1; $i <= date('Y')+1; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Valor Total <span class="text-danger">*</span></label>
                                <input type="text" name="valor_total" class="form-control valor" required placeholder="0,00">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Desconto</label>
                                <input type="text" name="desconto" class="form-control valor" value="0,00">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Multa</label>
                                <input type="text" name="multa" class="form-control valor" value="0,00">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Juros</label>
                                <input type="text" name="juros" class="form-control valor" value="0,00">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Valor Final</label>
                                <input type="text" id="valor_final" class="form-control" readonly style="background:#f0f2f5; font-weight:bold;">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Observações adicionais..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Registrar Pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Registrar Pagamento -->
    <div class="modal fade" id="modalRegistrarPagamento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-money-bill"></i> Registrar Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="registrar_pagamento">
                    <input type="hidden" name="id" id="pagamento_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Aluno</label>
                            <input type="text" id="aluno_nome" class="form-control" readonly disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tipo</label>
                            <input type="text" id="tipo_nome" class="form-control" readonly disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Valor Total</label>
                            <input type="text" id="valor_total_modal" class="form-control" readonly disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Valor Pago Anterior</label>
                            <input type="text" id="valor_pago_anterior" class="form-control" readonly disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Saldo Devedor</label>
                            <input type="text" id="saldo_devedor" class="form-control" readonly style="background:#fff3cd; font-weight:bold;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Valor a Pagar <span class="text-danger">*</span></label>
                            <input type="text" name="valor_pago" id="valor_pago" class="form-control valor" required placeholder="0,00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Forma de Pagamento <span class="text-danger">*</span></label>
                            <select name="metodo_pagamento" class="form-select" required>
                                <option value="dinheiro">💵 Dinheiro</option>
                                <option value="transferencia">🏦 Transferência Bancária</option>
                                <option value="deposito">💰 Depósito</option>
                                <option value="cheque">📄 Cheque</option>
                                <option value="multicaixa">💳 Multicaixa</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Data de Pagamento <span class="text-danger">*</span></label>
                            <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes_pagamento" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Confirmar Pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Formatar valores
        $('.valor').on('input', function() {
            let v = $(this).val().replace(/\D/g, '');
            v = (v / 100).toFixed(2).replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            $(this).val(v);
            calcularValorFinal();
        });
        
        function calcularValorFinal() {
            let valorTotal = parseFloat($('input[name="valor_total"]').val().replace(/\./g, '').replace(',', '.')) || 0;
            let desconto = parseFloat($('input[name="desconto"]').val().replace(/\./g, '').replace(',', '.')) || 0;
            let multa = parseFloat($('input[name="multa"]').val().replace(/\./g, '').replace(',', '.')) || 0;
            let juros = parseFloat($('input[name="juros"]').val().replace(/\./g, '').replace(',', '.')) || 0;
            let valorFinal = valorTotal - desconto + multa + juros;
            $('#valor_final').val(valorFinal.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + ' Kz');
        }
        
        // Seleção de tipo de pagamento
        $('.tipo-card').on('click', function() {
            $('.tipo-card').removeClass('selected');
            $(this).addClass('selected');
            let tipoId = $(this).data('tipo-id');
            $('#tipo_pagamento_id').val(tipoId);
        });
        
        // Alternar entre individual e turma
        $('input[name="tipo_selecao"]').on('change', function() {
            let tipo = $(this).val();
            if (tipo == 'individual') {
                $('#div_individual').show();
                $('#div_turma').hide();
                $('.select2-aluno').prop('required', true);
                $('.select2-turma').prop('required', false);
            } else {
                $('#div_individual').hide();
                $('#div_turma').show();
                $('.select2-aluno').prop('required', false);
                $('.select2-turma').prop('required', true);
            }
        });
        
        // Inicializar Select2
        $('.select2-aluno').select2({
            theme: 'bootstrap-5',
            placeholder: 'Selecione um aluno...',
            dropdownParent: $('#modalNovoPagamento')
        });
        
        $('.select2-turma').select2({
            theme: 'bootstrap-5',
            placeholder: 'Selecione uma turma...',
            dropdownParent: $('#modalNovoPagamento')
        });
        
        // Setar data de vencimento padrão
        let dataPadrao = new Date();
        dataPadrao.setDate(dataPadrao.getDate() + 30);
        $('input[name="data_vencimento"]').val(dataPadrao.toISOString().split('T')[0]);
        
        function abrirModalPagamento(pg) {
            $('#pagamento_id').val(pg.id);
            $('#aluno_nome').val(pg.aluno_nome);
            $('#tipo_nome').val(pg.tipo_nome);
            $('#valor_total_modal').val(formatarMoedaDisplay(pg.valor_total));
            $('#valor_pago_anterior').val(formatarMoedaDisplay(pg.valor_pago));
            let saldo = pg.valor_total - pg.valor_pago;
            $('#saldo_devedor').val(formatarMoedaDisplay(saldo));
            $('#valor_pago').val(formatarMoedaDisplay(saldo));
            
            new bootstrap.Modal(document.getElementById('modalRegistrarPagamento')).show();
        }
        
        function formatarMoedaDisplay(valor) {
            return valor.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + ' Kz';
        }
        
        function cancelarPagamento(id) {
            if(confirm('Tem certeza que deseja cancelar este pagamento?')) {
                $('<form method="POST"><input type="hidden" name="action" value="cancelar"><input type="hidden" name="id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        function excluirPagamento(id) {
            if(confirm('Tem certeza que deseja excluir este pagamento?')) {
                $('<form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        // GRÁFICOS
        // Gráfico de Tipos
        const tipoCtx = document.getElementById('tipoChart').getContext('2d');
        const tipoLabels = <?php echo json_encode(array_column($stats_por_tipo, 'tipo_nome')); ?>;
        const tipoData = <?php echo json_encode(array_column($stats_por_tipo, 'total_valor')); ?>;
        
        new Chart(tipoCtx, {
            type: 'doughnut',
            data: {
                labels: tipoLabels,
                datasets: [{
                    data: tipoData,
                    backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6c757d', '#e83e8c', '#6f42c1']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Gráfico de Turmas
        const turmaCtx = document.getElementById('turmaChart').getContext('2d');
        const turmaLabels = <?php echo json_encode(array_column($stats_por_turma, 'turma_nome')); ?>;
        const turmaData = <?php echo json_encode(array_column($stats_por_turma, 'total_devedor')); ?>;
        
        new Chart(turmaCtx, {
            type: 'bar',
            data: {
                labels: turmaLabels,
                datasets: [{
                    label: 'Valor em Débito (Kz)',
                    data: turmaData,
                    backgroundColor: '#dc3545'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString() + ' Kz';
                            }
                        }
                    }
                }
            }
        });
        
        // Atualizar valor final
        $('input[name="valor_total"], input[name="desconto"], input[name="multa"], input[name="juros"]').on('input', calcularValorFinal);
        calcularValorFinal();
        
        // Selecionar primeiro tipo automaticamente
        setTimeout(function() {
            $('.tipo-card:first').click();
        }, 100);
    </script>
</body>
</html>