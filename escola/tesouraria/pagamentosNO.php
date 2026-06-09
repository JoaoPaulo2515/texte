<?php
// escola/tesouraria/pagamentos.php - Gestão de Pagamentos

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
    header('Location: login.php?msg=acesso_negado');
    exit;
}

// ============================================
// PROCESSAR PAGAMENTOS DO CARRINHO
// ============================================
$success = '';
$error = '';
$cart = $_SESSION['pagamento_cart'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Adicionar ao carrinho
    if (isset($_POST['add_to_cart'])) {
        $aluno_id = (int)$_POST['aluno_id'];
        $tipo_pagamento = $_POST['tipo_pagamento'] ?? 'mensalidade';
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $referencia = trim($_POST['referencia'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $mes_referencia = (int)($_POST['mes_referencia'] ?? date('m'));
        $ano_referencia = (int)($_POST['ano_referencia'] ?? date('Y'));
        
        if ($aluno_id <= 0) {
            $error = "Selecione um aluno.";
        } elseif ($valor <= 0) {
            $error = "Valor do pagamento inválido.";
        } elseif ($tipo_pagamento == 'mensalidade') {
            // VERIFICAR SE A MENSALIDADE FOI GERADA
            $sql_check_mensalidade = "SELECT id, valor_total, valor_pago, status FROM mensalidades 
                                      WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                                      AND mes_referencia = :mes AND ano_referencia = :ano";
            $stmt_check = $conn->prepare($sql_check_mensalidade);
            $stmt_check->execute([
                ':escola_id' => $escola_id,
                ':aluno_id' => $aluno_id,
                ':mes' => $mes_referencia,
                ':ano' => $ano_referencia
            ]);
            $mensalidade_existe = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$mensalidade_existe) {
                $error = "Não é possível pagar esta mensalidade. O mês " . getMesNome($mes_referencia) . "/$ano_referencia não foi gerado para este aluno. Por favor, gere a mensalidade primeiro no módulo de Mensalidades.";
            } else {
                // VERIFICAR SE JÁ ESTÁ TOTALMENTE PAGA
                if ($mensalidade_existe['status'] == 'pago') {
                    $error = "Esta mensalidade (".getMesNome($mes_referencia)."/$ano_referencia) já foi totalmente paga. Não é possível efetuar novo pagamento.";
                } else {
                    // Calcular valor restante
                    $valor_pago_atual = $mensalidade_existe['valor_pago'] ?? 0;
                    $valor_restante = $mensalidade_existe['valor_total'] - $valor_pago_atual;
                    
                    if ($valor_restante <= 0) {
                        $error = "Esta mensalidade já está totalmente paga. Valor restante: " . formatarMoeda(0);
                    } elseif ($valor > $valor_restante) {
                        $error = "O valor do pagamento (" . formatarMoeda($valor) . ") excede o valor restante da mensalidade (" . formatarMoeda($valor_restante) . "). O valor máximo permitido é " . formatarMoeda($valor_restante) . ".";
                    } elseif ($valor <= 0) {
                        $error = "Valor do pagamento inválido.";
                    }
                }
            }
        }
        
        if (empty($error)) {
            // Adicionar ao carrinho
            $cart[] = [
                'aluno_id' => $aluno_id,
                'aluno_nome' => $_POST['aluno_nome'],
                'tipo_pagamento' => $tipo_pagamento,
                'valor' => $valor,
                'referencia' => $referencia,
                'observacoes' => $observacoes,
                'mes_referencia' => $mes_referencia,
                'ano_referencia' => $ano_referencia
            ];
            $_SESSION['pagamento_cart'] = $cart;
            $success = "Item adicionado ao carrinho!";
        }
    }
    
    // Remover do carrinho
    elseif (isset($_POST['remove_from_cart'])) {
        $index = (int)$_POST['cart_index'];
        if (isset($cart[$index])) {
            unset($cart[$index]);
            $_SESSION['pagamento_cart'] = array_values($cart);
            $success = "Item removido do carrinho!";
        }
    }
    
    // Finalizar todos os pagamentos
    elseif (isset($_POST['finalizar_pagamentos'])) {
        $forma_pagamento = $_POST['forma_pagamento'] ?? 'dinheiro';
        
        if (empty($cart)) {
            $error = "Carrinho vazio!";
        } else {
            try {
                $conn->beginTransaction();
                
                // Gerar número da fatura
                $numero_fatura = 'FT/' . date('Ymd') . '/' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                foreach ($cart as $item) {
                    // ANTES DE INSERIR, VERIFICAR NOVAMENTE SE A MENSALIDADE AINDA ESTÁ PENDENTE
                    if ($item['tipo_pagamento'] == 'mensalidade' && $item['mes_referencia'] > 0) {
                        $sql_check_final = "SELECT id, valor_total, valor_pago, status FROM mensalidades 
                                            WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                                            AND mes_referencia = :mes AND ano_referencia = :ano";
                        $stmt_check_final = $conn->prepare($sql_check_final);
                        $stmt_check_final->execute([
                            ':escola_id' => $escola_id,
                            ':aluno_id' => $item['aluno_id'],
                            ':mes' => $item['mes_referencia'],
                            ':ano' => $item['ano_referencia']
                        ]);
                        $mensalidade_final = $stmt_check_final->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$mensalidade_final) {
                            throw new Exception("Mensalidade de " . getMesNome($item['mes_referencia']) . "/{$item['ano_referencia']} não encontrada para o aluno " . $item['aluno_nome']);
                        }
                        
                        if ($mensalidade_final['status'] == 'pago') {
                            throw new Exception("Mensalidade de " . getMesNome($item['mes_referencia']) . "/{$item['ano_referencia']} do aluno " . $item['aluno_nome'] . " já foi totalmente paga anteriormente!");
                        }
                        
                        $valor_restante_final = $mensalidade_final['valor_total'] - ($mensalidade_final['valor_pago'] ?? 0);
                        if ($item['valor'] > $valor_restante_final) {
                            throw new Exception("Valor do pagamento excede o valor restante da mensalidade de " . getMesNome($item['mes_referencia']) . "/{$item['ano_referencia']} do aluno " . $item['aluno_nome']);
                        }
                    }
                    
                    // Registrar pagamento - CORRIGIDO: usar assinatura_id
                    $sql = "INSERT INTO pagamentos (escola_id, assinatura_id, tipo_pagamento, valor, metodo_pagamento, referente, observacoes, data_pagamento, usuario_id, status, numero_fatura, created_at) 
                            VALUES (:escola_id, :assinatura_id, :tipo_pagamento, :valor, :forma_pagamento, :referente, :observacoes, CURDATE(), :usuario_id, 'confirmado', :numero_fatura, NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':escola_id' => $escola_id,
                        ':assinatura_id' => $item['aluno_id'],
                        ':tipo_pagamento' => $item['tipo_pagamento'],
                        ':valor' => $item['valor'],
                        ':forma_pagamento' => $forma_pagamento,
                        ':referente' => $item['referencia'],
                        ':observacoes' => $item['observacoes'],
                        ':usuario_id' => $usuario_id,
                        ':numero_fatura' => $numero_fatura
                    ]);
                    $pagamento_id = $conn->lastInsertId();
                    
                    // Se for mensalidade, atualizar a mensalidade correspondente
                    if ($item['tipo_pagamento'] == 'mensalidade' && $item['mes_referencia'] > 0) {
                        $sql_check = "SELECT id, valor_total, valor_pago, status FROM mensalidades 
                                      WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                                      AND mes_referencia = :mes AND ano_referencia = :ano";
                        $stmt_check = $conn->prepare($sql_check);
                        $stmt_check->execute([
                            ':escola_id' => $escola_id,
                            ':aluno_id' => $item['aluno_id'],
                            ':mes' => $item['mes_referencia'],
                            ':ano' => $item['ano_referencia']
                        ]);
                        $mensalidade = $stmt_check->fetch(PDO::FETCH_ASSOC);
                        
                        if ($mensalidade) {
                            $novo_valor_pago = ($mensalidade['valor_pago'] ?? 0) + $item['valor'];
                            $novo_status = ($novo_valor_pago >= $mensalidade['valor_total']) ? 'pago' : 'parcial';
                            
                            $sql_update = "UPDATE mensalidades SET valor_pago = :valor_pago, status = :status, data_pagamento = CURDATE() 
                                           WHERE id = :id";
                            $stmt_update = $conn->prepare($sql_update);
                            $stmt_update->execute([
                                ':valor_pago' => $novo_valor_pago,
                                ':status' => $novo_status,
                                ':id' => $mensalidade['id']
                            ]);
                        } else {
                            // Criar nova mensalidade (caso não exista)
                            $sql_insert = "INSERT INTO mensalidades (escola_id, aluno_id, mes_referencia, ano_referencia, valor_total, valor_pago, status, data_vencimento, created_at) 
                                           VALUES (:escola_id, :aluno_id, :mes, :ano, :valor, :valor, 'pago', DATE_ADD(CURDATE(), INTERVAL 30 DAY), NOW())";
                            $stmt_insert = $conn->prepare($sql_insert);
                            $stmt_insert->execute([
                                ':escola_id' => $escola_id,
                                ':aluno_id' => $item['aluno_id'],
                                ':mes' => $item['mes_referencia'],
                                ':ano' => $item['ano_referencia'],
                                ':valor' => $item['valor']
                            ]);
                        }
                    }
                    
                    // Registrar no caixa
                    $sql_caixa = "INSERT INTO caixa (escola_id, tipo, categoria, descricao, valor, metodo_pagamento, referencia, usuario_id, pagamento_id, data_movimento, status, created_at) 
                                  VALUES (:escola_id, 'entrada', :categoria, :descricao, :valor, :forma_pagamento, :referencia, :usuario_id, :pagamento_id, CURDATE(), 'ativo', NOW())";
                    $stmt_caixa = $conn->prepare($sql_caixa);
                    $stmt_caixa->execute([
                        ':escola_id' => $escola_id,
                        ':categoria' => $item['tipo_pagamento'],
                        ':descricao' => "Pagamento de " . ucfirst($item['tipo_pagamento']) . " - " . $item['aluno_nome'],
                        ':valor' => $item['valor'],
                        ':forma_pagamento' => $forma_pagamento,
                        ':referencia' => $numero_fatura,
                        ':usuario_id' => $usuario_id,
                        ':pagamento_id' => $pagamento_id
                    ]);
                }
                
                $conn->commit();
                
                // Limpar carrinho
                unset($_SESSION['pagamento_cart']);
                $cart = [];
                
                $success = "Todos os pagamentos registrados com sucesso! Fatura Nº: $numero_fatura";
                  // FORÇAR RECARREGAMENTO DA PÁGINA PARA ATUALIZAR A LISTA
                echo "<script>
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                </script>";
                
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Erro ao registrar pagamentos: " . $e->getMessage();
            }
        }
    }
    
    // Limpar carrinho
    elseif (isset($_POST['clear_cart'])) {
        unset($_SESSION['pagamento_cart']);
        $cart = [];
        $success = "Carrinho limpo!";
    }
}

// ============================================
// ESTATÍSTICAS
// ============================================

// Estatísticas do dia
$sql_day = "SELECT 
                COUNT(*) as total_pagamentos,
                COALESCE(SUM(valor), 0) as valor_total,
                COUNT(DISTINCT assinatura_id) as alunos_atendidos
            FROM pagamentos 
            WHERE escola_id = :escola_id AND DATE(data_pagamento) = CURDATE()";
$stmt_day = $conn->prepare($sql_day);
$stmt_day->execute([':escola_id' => $escola_id]);
$stats_day = $stmt_day->fetch(PDO::FETCH_ASSOC);

// Estatísticas do mês
$sql_month = "SELECT 
                COUNT(*) as total_pagamentos,
                COALESCE(SUM(valor), 0) as valor_total,
                COUNT(DISTINCT assinatura_id) as alunos_atendidos,
                COALESCE(AVG(valor), 0) as ticket_medio
            FROM pagamentos 
            WHERE escola_id = :escola_id AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())";
$stmt_month = $conn->prepare($sql_month);
$stmt_month->execute([':escola_id' => $escola_id]);
$stats_month = $stmt_month->fetch(PDO::FETCH_ASSOC);

// Estatísticas por tipo de pagamento
$sql_type = "SELECT 
                tipo_pagamento,
                COUNT(*) as quantidade,
                COALESCE(SUM(valor), 0) as total
            FROM pagamentos 
            WHERE escola_id = :escola_id AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())
            GROUP BY tipo_pagamento";
$stmt_type = $conn->prepare($sql_type);
$stmt_type->execute([':escola_id' => $escola_id]);
$stats_by_type = $stmt_type->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas por forma de pagamento
$sql_method = "SELECT 
                metodo_pagamento,
                COUNT(*) as quantidade,
                COALESCE(SUM(valor), 0) as total
            FROM pagamentos 
            WHERE escola_id = :escola_id AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())
            GROUP BY metodo_pagamento
            ORDER BY total DESC";
$stmt_method = $conn->prepare($sql_method);
$stmt_method->execute([':escola_id' => $escola_id]);
$stats_by_method = $stmt_method->fetchAll(PDO::FETCH_ASSOC);

// Pagamentos por dia do mês (para o gráfico)
$sql_daily = "SELECT 
                DAY(data_pagamento) as dia,
                COALESCE(SUM(valor), 0) as total
            FROM pagamentos 
            WHERE escola_id = :escola_id AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())
            GROUP BY DAY(data_pagamento)
            ORDER BY dia ASC";
$stmt_daily = $conn->prepare($sql_daily);
$stmt_daily->execute([':escola_id' => $escola_id]);
$stats_daily = $stmt_daily->fetchAll(PDO::FETCH_ASSOC);

// Dívidas totais
$sql_debts = "SELECT 
                COUNT(*) as total_debitos,
                COALESCE(SUM(valor_total - COALESCE(valor_pago, 0)), 0) as valor_total_devedor
            FROM mensalidades 
            WHERE escola_id = :escola_id AND status IN ('pendente', 'parcial')";
$stmt_debts = $conn->prepare($sql_debts);
$stmt_debts->execute([':escola_id' => $escola_id]);
$stats_debts = $stmt_debts->fetch(PDO::FETCH_ASSOC);

// Meta do mês
$meta_mensal = 500000;
$percentual_meta = ($stats_month['valor_total'] > 0) ? ($stats_month['valor_total'] / $meta_mensal) * 100 : 0;

// ============================================
// BUSCAR DADOS PARA OS SELECTS
// ============================================

// Buscar alunos
$sql_alunos = "SELECT e.id, e.nome, e.matricula 
               FROM estudantes e 
               WHERE e.escola_id = :escola_id AND e.status = 'ativo'
               ORDER BY e.nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar últimas mensalidades para débitos
$sql_debitos = "SELECT m.*, e.nome as aluno_nome, e.matricula
                FROM mensalidades m
                JOIN estudantes e ON e.id = m.aluno_id
                WHERE m.escola_id = :escola_id AND m.status IN ('pendente', 'parcial')
                ORDER BY e.nome ASC, m.ano_referencia DESC, m.mes_referencia ASC
                LIMIT 10";
$stmt_debitos = $conn->prepare($sql_debitos);
$stmt_debitos->execute([':escola_id' => $escola_id]);
$debitos = $stmt_debitos->fetchAll(PDO::FETCH_ASSOC);

// Buscar últimos pagamentos
$sql_ultimos = "SELECT p.*, e.nome as aluno_nome, e.matricula 
                FROM pagamentos p
                JOIN estudantes e ON e.id = p.assinatura_id
                WHERE p.escola_id = :escola_id 
                ORDER BY p.data_pagamento DESC 
                LIMIT 10";
$stmt_ultimos = $conn->prepare($sql_ultimos);
$stmt_ultimos->execute([':escola_id' => $escola_id]);
$ultimos_pagamentos = $stmt_ultimos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getTipoPagamentoLabel($tipo) {
    $tipos = [
        'mensalidade' => '<span class="badge bg-primary">Mensalidade</span>',
        'matricula' => '<span class="badge bg-success">Matrícula</span>',
        'certificado' => '<span class="badge bg-info">Certificado</span>',
        'material' => '<span class="badge bg-warning">Material</span>',
        'outro' => '<span class="badge bg-secondary">Outro</span>'
    ];
    return $tipos[$tipo] ?? '<span class="badge bg-secondary">' . $tipo . '</span>';
}

function getFormaPagamentoIcone($forma) {
    switch ($forma) {
        case 'dinheiro': return '<i class="fas fa-money-bill-wave text-success"></i> Dinheiro';
        case 'transferencia': return '<i class="fas fa-university text-primary"></i> Transferência';
        case 'deposito': return '<i class="fas fa-money-bill text-info"></i> Depósito';
        case 'cheque': return '<i class="fas fa-check-circle text-warning"></i> Cheque';
        case 'multicaixa': return '<i class="fas fa-credit-card text-secondary"></i> Multicaixa';
        default: return '<i class="fas fa-question-circle"></i> ' . ucfirst($forma);
    }
}

function getMesNome($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamentos | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content-tesouraria {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content-tesouraria { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-success { background: #28a745; border: none; }
        .btn-success:hover { background: #218838; }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        .stat-card h3 { font-size: 2rem; margin: 0; font-weight: bold; }
        .stat-card p { margin: 0; opacity: 0.9; }
        .stat-card .icon { font-size: 3rem; opacity: 0.3; position: absolute; right: 20px; top: 20px; }
        
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .table-pagamentos td { vertical-align: middle; }
        .debito-row { background-color: #fff3cd; }
        .valor-debito { color: #dc3545; font-weight: bold; }
        
        .info-aluno { font-size: 0.85rem; color: #6c757d; }
        
        .pix-area { background: #e8f5e9; border-radius: 10px; padding: 15px; margin-top: 15px; }
        
        .cart-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .cart-total {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content-tesouraria">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-credit-card"></i> Gestão de Pagamentos</h2>
                <p class="text-muted">Registrar e gerenciar pagamentos de mensalidades e serviços</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPagamento">
                    <i class="fas fa-plus"></i> Novo Pagamento
                </button>
                <?php if (!empty($cart)): ?>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCarrinho">
                    <i class="fas fa-shopping-cart"></i> Carrinho (<?php echo count($cart); ?>)
                </button>
                <?php endif; ?>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- ESTATÍSTICAS -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <p>Hoje (Arrecadado)</p>
                    <h3><?php echo formatarMoeda($stats_day['valor_total'] ?? 0); ?></h3>
                    <small><?php echo number_format($stats_day['total_pagamentos'] ?? 0); ?> pagamentos | <?php echo number_format($stats_day['alunos_atendidos'] ?? 0); ?> alunos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                    <p>Este Mês</p>
                    <h3><?php echo formatarMoeda($stats_month['valor_total'] ?? 0); ?></h3>
                    <small><?php echo number_format($stats_month['total_pagamentos'] ?? 0); ?> transações | Ticket médio: <?php echo formatarMoeda($stats_month['ticket_medio'] ?? 0); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <p>Dívidas em Aberto</p>
                    <h3><?php echo formatarMoeda($stats_debts['valor_total_devedor'] ?? 0); ?></h3>
                    <small><?php echo number_format($stats_debts['total_debitos'] ?? 0); ?> mensalidades pendentes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="icon"><i class="fas fa-bullseye"></i></div>
                    <p>Meta do Mês</p>
                    <h3><?php echo number_format($percentual_meta, 1); ?>%</h3>
                    <div class="progress mt-2" style="height: 5px;">
                        <div class="progress-bar bg-white" style="width: <?php echo min($percentual_meta, 100); ?>%"></div>
                    </div>
                    <small>Meta: <?php echo formatarMoeda($meta_mensal); ?></small>
                </div>
            </div>
        </div>
        
        <!-- GRÁFICO DE ARRECADAÇÃO DIÁRIA -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Arrecadação Diária - <?php echo date('F/Y'); ?></h5>
                    </div>
                    <div class="card-body">
                        <canvas id="dailyChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Distribuição por Tipo</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="typeChart" height="200"></canvas>
                        <hr>
                        <?php foreach ($stats_by_type as $type): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span><?php echo ucfirst($type['tipo_pagamento']); ?></span>
                            <span class="fw-bold"><?php echo formatarMoeda($type['total']); ?></span>
                            <span class="text-muted">(<?php echo $type['quantidade']; ?>x)</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Formas de Pagamento</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="methodChart" height="200"></canvas>
                        <div class="row mt-3">
                            <?php foreach ($stats_by_method as $method): ?>
                            <div class="col-6 mb-2">
                                <div class="d-flex justify-content-between">
                                    <span><?php echo getFormaPagamentoIcone($method['metodo_pagamento']); ?></span>
                                    <span class="fw-bold"><?php echo formatarMoeda($method['total']); ?></span>
                                </div>
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo ($stats_month['valor_total'] > 0) ? ($method['total'] / $stats_month['valor_total']) * 100 : 0; ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Últimos Pagamentos</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>Data</th><th>Aluno</th><th>Valor</th><th>Forma</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($ultimos_pagamentos, 0, 5) as $pg): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                                        <td><?php echo htmlspecialchars(substr($pg['aluno_nome'], 0, 20)); ?></small></td>
                                        <td class="text-success"><?php echo formatarMoeda($pg['valor']); ?></td>
                                        <td><?php echo getFormaPagamentoIcone($pg['metodo_pagamento']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dívidas em Aberto (original) -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Dívidas em Aberto</h5>
            </div>
            <div class="card-body">
                <?php if (empty($debitos)): ?>
                    <div class="alert alert-success text-center">
                        <i class="fas fa-check-circle"></i> Nenhuma dívida em aberto no momento!
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Aluno</th>
                                    <th>Mês/Ano</th>
                                    <th>Valor Total</th>
                                    <th>Valor Pago</th>
                                    <th>Saldo Devedor</th>
                                    <th>Status</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($debitos as $debito): ?>
                                <tr class="debito-row">
                                    <td><strong><?php echo htmlspecialchars($debito['aluno_nome']); ?></strong><br>
                                        <small class="info-aluno">Mat: <?php echo htmlspecialchars($debito['matricula'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo getMesNome($debito['mes_referencia']) . '/' . $debito['ano_referencia']; ?></td>
                                    <td><?php echo formatarMoeda($debito['valor_total']); ?></td>
                                    <td><?php echo formatarMoeda($debito['valor_pago'] ?? 0); ?></td>
                                    <td class="valor-debito"><?php echo formatarMoeda(($debito['valor_total'] - ($debito['valor_pago'] ?? 0))); ?></td>
                                    <td><?php echo $debito['status'] == 'pendente' ? '<span class="badge bg-danger">Pendente</span>' : '<span class="badge bg-warning text-dark">Parcial</span>'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success" onclick="adicionarAoCarrinhoRapido(<?php echo $debito['aluno_id']; ?>, '<?php echo htmlspecialchars($debito['aluno_nome']); ?>', <?php echo $debito['valor_total'] - ($debito['valor_pago'] ?? 0); ?>, <?php echo $debito['mes_referencia']; ?>, <?php echo $debito['ano_referencia']; ?>)">
                                            <i class="fas fa-cart-plus"></i> Pagar
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Últimos Pagamentos (original) -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Últimos Pagamentos</h5>
            </div>
            <div class="card-body">
                <?php if (empty($ultimos_pagamentos)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhum pagamento registrado ainda.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-pagamentos">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Aluno</th>
                                    <th>Tipo</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Referência</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ultimos_pagamentos as $pg): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($pg['aluno_nome']); ?></strong><br><small class="text-muted">Mat: <?php echo htmlspecialchars($pg['matricula'] ?? ''); ?></small></td>
                                    <td><?php echo getTipoPagamentoLabel($pg['tipo_pagamento']); ?></td>
                                    <td class="text-success fw-bold"><?php echo formatarMoeda($pg['valor']); ?></td>
                                    <td><?php echo getFormaPagamentoIcone($pg['metodo_pagamento']); ?></td>
                                    <td><?php echo htmlspecialchars($pg['referente'] ?: '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Pagamento (CARRINHO) -->
    <div class="modal fade" id="modalNovoPagamento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-cart-plus"></i> Adicionar ao Carrinho</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Adicione itens ao carrinho. No final, clique em "Finalizar Carrinho" para registrar todos os pagamentos de uma só vez.
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Aluno <span class="text-danger">*</span></label>
                                <select name="aluno_id" id="aluno_id" class="form-select" required>
                                    <option value="">Selecione um aluno</option>
                                    <?php foreach ($alunos as $aluno): ?>
                                    <option value="<?php echo $aluno['id']; ?>">
                                        <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Pagamento</label>
                                <select name="tipo_pagamento" id="tipo_pagamento" class="form-select" onchange="toggleMensalidade()">
                                    <option value="mensalidade">📅 Mensalidade</option>
                                    <option value="matricula">📝 Matrícula</option>
                                    <option value="certificado">🎓 Certificado</option>
                                    <option value="material">📚 Material Escolar</option>
                                    <option value="outro">📌 Outro</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valor <span class="text-danger">*</span></label>
                                <input type="text" name="valor" id="valor" class="form-control" placeholder="0,00" required>
                            </div>
                        </div>
                        <div class="row" id="div_mensalidade">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mês Referência</label>
                                <select name="mes_referencia" class="form-select">
                                    <option value="1">Janeiro</option>
                                    <option value="2">Fevereiro</option>
                                    <option value="3">Março</option>
                                    <option value="4">Abril</option>
                                    <option value="5">Maio</option>
                                    <option value="6">Junho</option>
                                    <option value="7">Julho</option>
                                    <option value="8">Agosto</option>
                                    <option value="9">Setembro</option>
                                    <option value="10">Outubro</option>
                                    <option value="11">Novembro</option>
                                    <option value="12">Dezembro</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ano Referência</label>
                                <select name="ano_referencia" class="form-select">
                                    <?php for($i = date('Y')-1; $i <= date('Y')+1; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Referência/Descrição</label>
                            <input type="text" name="referencia" class="form-control" placeholder="Ex: Mensalidade Fevereiro, Material escolar, etc.">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                        
                        <input type="hidden" name="aluno_nome" id="aluno_nome_hidden">
                        
                        <div class="pix-area" id="pixArea" style="display: none;">
                            <i class="fab fa-pix"></i> 
                            <strong>Chave PIX:</strong> suporte@sige.ao<br>
                            <small class="text-muted">Para transferências PIX, utilize a chave acima</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="add_to_cart" class="btn btn-primary">Adicionar ao Carrinho</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Carrinho de Compras -->
    <div class="modal fade" id="modalCarrinho" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-shopping-cart"></i> Carrinho de Pagamentos</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <?php if (empty($cart)): ?>
                            <div class="alert alert-warning text-center">
                                <i class="fas fa-cart-empty"></i> Carrinho vazio!
                            </div>
                        <?php else: ?>
                            <?php 
                            $subtotal = 0;
                            foreach ($cart as $index => $item): 
                                $subtotal += $item['valor'];
                            ?>
                            <div class="cart-item">
                                <div class="row align-items-center">
                                    <div class="col-md-5">
                                        <strong><?php echo htmlspecialchars($item['aluno_nome']); ?></strong><br>
                                        <small class="text-muted"><?php echo ucfirst($item['tipo_pagamento']); ?></small>
                                        <?php if ($item['tipo_pagamento'] == 'mensalidade' && $item['mes_referencia'] > 0): ?>
                                        <br><small><?php echo getMesNome($item['mes_referencia']) . '/' . $item['ano_referencia']; ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="text-muted"><?php echo htmlspecialchars($item['referencia'] ?: '-'); ?></span>
                                    </div>
                                    <div class="col-md-2 text-end">
                                        <strong class="text-success"><?php echo formatarMoeda($item['valor']); ?></strong>
                                    </div>
                                    <div class="col-md-1">
                                        <button type="submit" name="remove_from_cart" class="btn btn-sm btn-danger" onclick="this.form.cart_index.value=<?php echo $index; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <input type="hidden" name="cart_index" value="">
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <div class="cart-total">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Total de Itens:</strong> <?php echo count($cart); ?><br>
                                        <strong>Valor Total:</strong>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <h4 class="mb-0"><?php echo formatarMoeda($subtotal); ?></h4>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <label class="form-label">Forma de Pagamento <span class="text-danger">*</span></label>
                                <select name="forma_pagamento" class="form-select" required>
                                    <option value="dinheiro">💵 Dinheiro</option>
                                    <option value="transferencia">🏦 Transferência Bancária</option>
                                    <option value="deposito">💰 Depósito</option>
                                    <option value="cheque">📄 Cheque</option>
                                    <option value="multicaixa">💳 Multicaixa</option>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <?php if (!empty($cart)): ?>
                        <button type="submit" name="clear_cart" class="btn btn-secondary" onclick="return confirm('Tem certeza que deseja limpar o carrinho?')">
                            <i class="fas fa-trash-alt"></i> Limpar Carrinho
                        </button>
                        <button type="submit" name="finalizar_pagamentos" class="btn btn-success">
                            <i class="fas fa-check-circle"></i> Finalizar Pagamentos
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Máscara para valor
        function formatarValor(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('#valor').on('input', function() {
            let valor = $(this).val();
            $(this).val(formatarValor(valor));
        });
        
        function toggleMensalidade() {
            let tipo = $('#tipo_pagamento').val();
            if (tipo === 'mensalidade') {
                $('#div_mensalidade').show();
            } else {
                $('#div_mensalidade').hide();
            }
        }
        
        function togglePixArea() {
            let forma = $('select[name="forma_pagamento"]').val();
            if (forma === 'transferencia') {
                $('#pixArea').show();
            } else {
                $('#pixArea').hide();
            }
        }
        
        // Pegar nome do aluno para o carrinho
        $('#aluno_id').on('change', function() {
            let nome = $(this).find('option:selected').text();
            $('#aluno_nome_hidden').val(nome);
        });
        
        function adicionarAoCarrinhoRapido(alunoId, alunoNome, valor, mes, ano) {
            // Preencher o formulário do modal
            $('#aluno_id').val(alunoId);
            $('#aluno_nome_hidden').val(alunoNome);
            $('#valor').val(formatarValor(valor.toString()));
            $('select[name="mes_referencia"]').val(mes);
            $('select[name="ano_referencia"]').val(ano);
            $('#tipo_pagamento').val('mensalidade');
            $('#referencia').val('Pagamento de mensalidade');
            toggleMensalidade();
            
            // Abrir modal e adicionar automaticamente
            $('#modalNovoPagamento').modal('show');
        }
        
        // GRÁFICOS
        // Gráfico Diário
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        const dailyData = <?php 
            $days = array_fill(1, date('t'), 0);
            foreach ($stats_daily as $d) {
                $days[$d['dia']] = $d['total'];
            }
            echo json_encode(array_values($days));
        ?>;
        
        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(range(1, date('t'))); ?>,
                datasets: [{
                    label: 'Arrecadação (Kz)',
                    data: dailyData,
                    backgroundColor: 'rgba(0, 107, 62, 0.6)',
                    borderColor: '#006B3E',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Kz ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de Tipos
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        const typeLabels = <?php echo json_encode(array_column($stats_by_type, 'tipo_pagamento')); ?>;
        const typeData = <?php echo json_encode(array_column($stats_by_type, 'total')); ?>;
        
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: typeLabels.map(t => t === 'mensalidade' ? 'Mensalidade' : t === 'matricula' ? 'Matrícula' : t === 'certificado' ? 'Certificado' : t === 'material' ? 'Material' : 'Outro'),
                datasets: [{
                    data: typeData,
                    backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#ffc107', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Gráfico de Métodos
        const methodCtx = document.getElementById('methodChart').getContext('2d');
        const methodLabels = <?php echo json_encode(array_column($stats_by_method, 'metodo_pagamento')); ?>;
        const methodData = <?php echo json_encode(array_column($stats_by_method, 'total')); ?>;
        
        new Chart(methodCtx, {
            type: 'pie',
            data: {
                labels: methodLabels,
                datasets: [{
                    data: methodData,
                    backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Inicializar
        toggleMensalidade();
        togglePixArea();
        
        // Atualizar select de forma de pagamento para o PIX
        $('select[name="forma_pagamento"]').on('change', togglePixArea);
    </script>
</body>
</html>