<?php
// escola/financeiro/novos_pagamentos.php - Novo Sistema de Pagamentos

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
// PROCESSAR PAGAMENTOS
// ============================================
$success = '';
$error = '';
$cart = $_SESSION['pagamento_cart'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Adicionar ao carrinho
    if (isset($_POST['add_to_cart'])) {
        $aluno_id = (int)$_POST['aluno_id'];
        $tipo_pagamento_id = (int)$_POST['tipo_pagamento_id'];
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $referencia = trim($_POST['referencia'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $mes_referencia = (int)($_POST['mes_referencia'] ?? date('m'));
        $ano_referencia = (int)($_POST['ano_referencia'] ?? date('Y'));
        
        // Buscar dados do tipo de pagamento
        $sql_tipo = "SELECT id, nome, icone, cor FROM tipos_pagamento WHERE id = :id AND ativo = 1";
        $stmt_tipo = $conn->prepare($sql_tipo);
        $stmt_tipo->execute([':id' => $tipo_pagamento_id]);
        $tipo = $stmt_tipo->fetch(PDO::FETCH_ASSOC);
        
        if ($aluno_id <= 0) {
            $error = "Selecione um aluno.";
        } elseif ($valor <= 0) {
            $error = "Valor do pagamento inválido.";
        } elseif (!$tipo) {
            $error = "Tipo de pagamento inválido.";
        } elseif ($tipo['nome'] == 'Mensalidade') {
            // Verificar se a mensalidade foi gerada
            $sql_check = "SELECT id, valor_total, valor_pago, status FROM mensalidades 
                          WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                          AND mes_referencia = :mes AND ano_referencia = :ano";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':escola_id' => $escola_id,
                ':aluno_id' => $aluno_id,
                ':mes' => $mes_referencia,
                ':ano' => $ano_referencia
            ]);
            $mensalidade = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$mensalidade) {
                $error = "Mensalidade não encontrada. Gere a mensalidade primeiro.";
            } elseif ($mensalidade['status'] == 'pago') {
                $error = "Esta mensalidade já está totalmente paga.";
            } else {
                $valor_restante = $mensalidade['valor_total'] - ($mensalidade['valor_pago'] ?? 0);
                if ($valor > $valor_restante) {
                    $error = "Valor excede o restante da mensalidade. Restante: " . formatarMoeda($valor_restante);
                }
            }
        }
        
        if (empty($error)) {
            $cart[] = [
                'aluno_id' => $aluno_id,
                'aluno_nome' => $_POST['aluno_nome'],
                'tipo_pagamento_id' => $tipo_pagamento_id,
                'tipo_pagamento_nome' => $tipo['nome'],
                'tipo_icone' => $tipo['icone'],
                'tipo_cor' => $tipo['cor'],
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
                
                $numero_fatura = gerarNumeroFatura($conn, $escola_id);
                $comprovativo_numero = gerarNumeroComprovativo($conn, $escola_id);
                
                foreach ($cart as $item) {
                    // Registrar pagamento
                    $sql = "INSERT INTO pagamentos (escola_id, assinatura_id, tipo_pagamento_id, tipo_pagamento, valor, metodo_pagamento, referente, observacoes, data_pagamento, usuario_id, status, numero_fatura, comprovativo_numero, created_at) 
                            VALUES (:escola_id, :assinatura_id, :tipo_pagamento_id, :tipo_pagamento, :valor, :metodo_pagamento, :referente, :observacoes, CURDATE(), :usuario_id, 'confirmado', :numero_fatura, :comprovativo_numero, NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':escola_id' => $escola_id,
                        ':assinatura_id' => $item['aluno_id'],
                        ':tipo_pagamento_id' => $item['tipo_pagamento_id'],
                        ':tipo_pagamento' => $item['tipo_pagamento_nome'],
                        ':valor' => $item['valor'],
                        ':metodo_pagamento' => $forma_pagamento,
                        ':referente' => !empty($item['referencia']) ? $item['referencia'] : $item['tipo_pagamento_nome'],
                        ':observacoes' => $item['observacoes'],
                        ':usuario_id' => $usuario_id,
                        ':numero_fatura' => $numero_fatura,
                        ':comprovativo_numero' => $comprovativo_numero
                    ]);
                    $pagamento_id = $conn->lastInsertId();
                    
                    // Se for mensalidade, atualizar
                    if ($item['tipo_pagamento_nome'] == 'Mensalidade' && $item['mes_referencia'] > 0) {
                        $sql_update = "UPDATE mensalidades SET valor_pago = valor_pago + :valor, 
                                       status = CASE WHEN valor_pago + :valor >= valor_total THEN 'pago' ELSE 'parcial' END,
                                       data_pagamento = CURDATE()
                                       WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                                       AND mes_referencia = :mes AND ano_referencia = :ano";
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->execute([
                            ':valor' => $item['valor'],
                            ':escola_id' => $escola_id,
                            ':aluno_id' => $item['aluno_id'],
                            ':mes' => $item['mes_referencia'],
                            ':ano' => $item['ano_referencia']
                        ]);
                    }
                    
                    // Registrar no caixa
                    $sql_caixa = "INSERT INTO caixa (escola_id, tipo, categoria, descricao, valor, metodo_pagamento, referencia, usuario_id, pagamento_id, data_movimento, status, created_at) 
                                  VALUES (:escola_id, 'entrada', :categoria, :descricao, :valor, :metodo_pagamento, :referencia, :usuario_id, :pagamento_id, CURDATE(), 'ativo', NOW())";
                    $stmt_caixa = $conn->prepare($sql_caixa);
                    $stmt_caixa->execute([
                        ':escola_id' => $escola_id,
                        ':categoria' => $item['tipo_pagamento_nome'],
                        ':descricao' => $item['referencia'] ?: $item['tipo_pagamento_nome'],
                        ':valor' => $item['valor'],
                        ':metodo_pagamento' => $forma_pagamento,
                        ':referencia' => $numero_fatura,
                        ':usuario_id' => $usuario_id,
                        ':pagamento_id' => $pagamento_id
                    ]);
                }
                
                $conn->commit();
                
                unset($_SESSION['pagamento_cart']);
                $cart = [];
                
                echo "<script>
                    alert('Pagamentos registrados com sucesso!\\nFatura Nº: $numero_fatura\\nComprovativo Nº: $comprovativo_numero');
                    window.location.href = window.location.href;
                </script>";
                exit;
                
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
// FUNÇÕES AUXILIARES
// ============================================

function gerarNumeroFatura($conn, $escola_id) {
    $ano = date('Y');
    $sql = "SELECT COUNT(*) as total FROM pagamentos WHERE escola_id = :escola_id AND YEAR(created_at) = :ano AND numero_fatura IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = $count ? $count['total'] : 0;
    return "FT/" . $ano . "/" . str_pad($total + 1, 5, '0', STR_PAD_LEFT);
}

function gerarNumeroComprovativo($conn, $escola_id) {
    $ano = date('Y');
    $sql = "SELECT COUNT(*) as total FROM pagamentos WHERE escola_id = :escola_id AND YEAR(created_at) = :ano";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = $count ? $count['total'] : 0;
    return "C" . str_pad($total + 1, 8, '0', STR_PAD_LEFT) . "/" . $ano;
}

function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getMesNome($mes) {
    $meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
              7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    return $meses[$mes] ?? '-';
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar tipos de pagamento ativos
$sql_tipos = "SELECT id, nome, descricao, icone, cor, ordem FROM tipos_pagamento WHERE ativo = 1 ORDER BY ordem ASC";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute();
$tipos_pagamento = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos ativos
$sql_alunos = "SELECT id, nome, matricula, curso FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novos Pagamentos | Financeiro | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-success { background: #28a745; border: none; }
        .btn-success:hover { background: #218838; }
        
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .cart-item { background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 10px; transition: all 0.2s; }
        .cart-item:hover { background: #e9ecef; }
        .cart-total { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; border-radius: 10px; padding: 20px; margin-top: 15px; }
        
        .tipo-card {
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        .tipo-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .tipo-card.selected {
            border-color: #006B3E;
            background: #e8f5e9;
        }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .info-aluno { font-size: 0.85rem; color: #6c757d; }
    </style>
</head>
<body>
    <?php include '../tesouraria/menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-plus-circle"></i> Novos Pagamentos</h2>
                <p class="text-muted">Registrar pagamentos de mensalidades e serviços</p>
            </div>
            <div>
                <?php if (!empty($cart)): ?>
                <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalCarrinho">
                    <i class="fas fa-shopping-cart"></i> Carrinho (<?php echo count($cart); ?>)
                </button>
                <?php endif; ?>
                <a href="index.php" class="btn-voltar">
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
        
        <!-- Formulário de Pagamento -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-credit-card"></i> Registrar Novo Pagamento</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="formPagamento">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Aluno <span class="text-danger">*</span></label>
                            <select name="aluno_id" id="aluno_id" class="form-select" required>
                                <option value="">Selecione um aluno</option>
                                <?php foreach ($alunos as $aluno): ?>
                                <option value="<?php echo $aluno['id']; ?>">
                                    <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="aluno_nome" id="aluno_nome_hidden">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Valor <span class="text-danger">*</span></label>
                            <input type="text" name="valor" id="valor" class="form-control" placeholder="0,00" required>
                        </div>
                    </div>
                    
                    <!-- Tipos de Pagamento -->
                    <div class="mb-4">
                        <label class="form-label">Tipo de Pagamento <span class="text-danger">*</span></label>
                        <div class="row">
                            <?php foreach ($tipos_pagamento as $tipo): ?>
                            <div class="col-md-3 mb-2">
                                <div class="card tipo-card" data-tipo-id="<?php echo $tipo['id']; ?>" data-tipo-nome="<?php echo $tipo['nome']; ?>" style="border-left: 4px solid <?php echo $tipo['cor']; ?>;">
                                    <div class="card-body p-3 text-center">
                                        <i class="<?php echo $tipo['icone']; ?>" style="font-size: 1.5rem; color: <?php echo $tipo['cor']; ?>;"></i>
                                        <div class="mt-1 fw-bold"><?php echo $tipo['nome']; ?></div>
                                        <small class="text-muted"><?php echo $tipo['descricao']; ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="tipo_pagamento_id" id="tipo_pagamento_id" value="">
                    </div>
                    
                    <div class="row" id="div_mensalidade" style="display:none;">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Mês Referência</label>
                            <select name="mes_referencia" class="form-select">
                                <?php for($i = 1; $i <= 12; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $i == date('m') ? 'selected' : ''; ?>><?php echo getMesNome($i); ?></option>
                                <?php endfor; ?>
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
                        <input type="text" name="referencia" id="referencia" class="form-control" placeholder="Ex: Mensalidade Fevereiro, Material escolar, etc.">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Observações</label>
                        <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                    </div>
                    
                    <div class="text-end">
                        <button type="submit" name="add_to_cart" class="btn btn-primary btn-lg">
                            <i class="fas fa-cart-plus"></i> Adicionar ao Carrinho
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Carrinho -->
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
                            <div class="alert alert-warning text-center">Carrinho vazio!</div>
                        <?php else: ?>
                            <?php $subtotal = 0; foreach ($cart as $index => $item): $subtotal += $item['valor']; ?>
                            <div class="cart-item">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <strong><?php echo htmlspecialchars($item['aluno_nome']); ?></strong><br>
                                        <small class="text-muted">
                                            <i class="<?php echo $item['tipo_icone']; ?>"></i> <?php echo $item['tipo_pagamento_nome']; ?>
                                        </small>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="text-muted"><?php echo htmlspecialchars($item['referencia'] ?: '-'); ?></span>
                                    </div>
                                    <div class="col-md-2 text-end">
                                        <strong class="text-success"><?php echo formatarMoeda($item['valor']); ?></strong>
                                    </div>
                                    <div class="col-md-2 text-end">
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
                                        <h3 class="mb-0"><?php echo formatarMoeda($subtotal); ?></h3>
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
                        <button type="submit" name="clear_cart" class="btn btn-secondary" onclick="return confirm('Limpar carrinho?')">Limpar</button>
                        <button type="submit" name="finalizar_pagamentos" class="btn btn-success">Finalizar Pagamentos</button>
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
        function formatarValor(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('#valor').on('input', function() {
            $(this).val(formatarValor($(this).val()));
        });
        
        // Seleção de tipo de pagamento
        $('.tipo-card').on('click', function() {
            $('.tipo-card').removeClass('selected');
            $(this).addClass('selected');
            let tipoId = $(this).data('tipo-id');
            let tipoNome = $(this).data('tipo-nome');
            $('#tipo_pagamento_id').val(tipoId);
            
            // Mostrar campos de mensalidade se for mensalidade
            if (tipoNome === 'Mensalidade') {
                $('#div_mensalidade').show();
                $('#referencia').attr('placeholder', 'Ex: Mensalidade de Fevereiro/2024');
            } else {
                $('#div_mensalidade').hide();
                $('#referencia').attr('placeholder', 'Ex: ' + tipoNome + ' - Descrição');
            }
        });
        
        // Pegar nome do aluno
        $('#aluno_id').on('change', function() {
            let nome = $(this).find('option:selected').text();
            $('#aluno_nome_hidden').val(nome);
        });
    </script>
</body>
</html>