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
// PAGINAÇÃO PARA DÍVIDAS EM ABERTO
// ============================================
$pagina_debitos = isset($_GET['pagina_debitos']) ? (int)$_GET['pagina_debitos'] : 1;
$registros_debitos_por_pagina = 10;
$offset_debitos = ($pagina_debitos - 1) * $registros_debitos_por_pagina;

// ============================================
// PAGINAÇÃO PARA ÚLTIMOS PAGAMENTOS
// ============================================
$pagina_ultimos = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$registros_ultimos_por_pagina = 10;
$offset_ultimos = ($pagina_ultimos - 1) * $registros_ultimos_por_pagina;

// ============================================
// PROCESSAR PAGAMENTOS DO CARRINHO
// ============================================
$success = '';
$error = '';
$cart = $_SESSION['pagamento_cart'] ?? [];

// Buscar tipos de pagamento do banco de dados
$sql_tipos = "SELECT id, nome, icone, cor, descricao FROM tipos_pagamento WHERE ativo = 1 ORDER BY ordem ASC";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute();
$tipos_pagamento_db = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Adicionar ao carrinho
    if (isset($_POST['add_to_cart'])) {
        $aluno_id = (int)$_POST['aluno_id'];
        $tipo_pagamento_id = (int)$_POST['tipo_pagamento_id'];
        $tipo_pagamento_nome = $_POST['tipo_pagamento_nome'];
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $referencia = trim($_POST['referencia'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $mes_referencia = (int)($_POST['mes_referencia'] ?? date('m'));
        $ano_referencia = (int)($_POST['ano_referencia'] ?? date('Y'));
        $quem_pagou = trim($_POST['quem_pagou'] ?? '');
        
        if ($aluno_id <= 0) {
            $error = "Selecione um aluno.";
        } elseif ($valor <= 0) {
            $error = "Valor do pagamento inválido.";
        } elseif ($tipo_pagamento_nome == 'mensalidade') {
            if ($mes_referencia <= 0 || $mes_referencia > 12) {
                $error = "Selecione o mês de referência da mensalidade.";
            } elseif ($ano_referencia <= 0) {
                $error = "Selecione o ano de referência da mensalidade.";
            } else {
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
                    $error = "Não é possível pagar esta mensalidade. O mês " . getMesNome($mes_referencia) . "/$ano_referencia não foi gerado para este aluno.";
                } elseif ($mensalidade['status'] == 'pago') {
                    $error = "Esta mensalidade já foi totalmente paga.";
                } else {
                    $valor_restante = $mensalidade['valor_total'] - ($mensalidade['valor_pago'] ?? 0);
                    if ($valor > $valor_restante) {
                        $error = "Valor excede o restante. Restante: " . formatarMoeda($valor_restante);
                    }
                }
            }
        } else {
            $sql_check = "SELECT id, valor_total, valor_pago, status 
                          FROM outros_pagamentos 
                          WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                          AND tipo_pagamento_id = :tipo_id
                          AND ano_referencia = :ano
                          AND status IN ('pendente', 'parcial')";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':escola_id' => $escola_id,
                ':aluno_id' => $aluno_id,
                ':tipo_id' => $tipo_pagamento_id,
                ':ano' => $ano_referencia
            ]);
            $outro_pagamento = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$outro_pagamento) {
                $error = "Não foi encontrado um lançamento de '{$tipo_pagamento_nome}' para este aluno no período selecionado.";
            } elseif ($outro_pagamento['status'] == 'pago') {
                $error = "Este pagamento já foi totalmente quitado.";
            } else {
                $valor_restante = $outro_pagamento['valor_total'] - ($outro_pagamento['valor_pago'] ?? 0);
                if ($valor > $valor_restante) {
                    $error = "Valor excede o restante. Restante: " . formatarMoeda($valor_restante);
                }
            }
        }
        
        if (empty($error)) {
            $cart[] = [
                'aluno_id' => $aluno_id,
                'aluno_nome' => $_POST['aluno_nome'],
                'aluno_matricula' => $_POST['aluno_matricula'],
                'tipo_pagamento_id' => $tipo_pagamento_id,
                'tipo_pagamento_nome' => $tipo_pagamento_nome,
                'valor' => $valor,
                'referencia' => $referencia,
                'observacoes' => $observacoes,
                'mes_referencia' => $mes_referencia,
                'ano_referencia' => $ano_referencia,
                'quem_pagou' => $quem_pagou
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
   // Finalizar todos os pagamentos
elseif (isset($_POST['finalizar_pagamentos'])) {
    $forma_pagamento = $_POST['forma_pagamento'] ?? 'dinheiro';
    $numero_referencia = trim($_POST['numero_referencia'] ?? '');
    $quem_recebeu = trim($_POST['quem_recebeu'] ?? $usuario_nome);
    $observacoes_finais = trim($_POST['observacoes_finais'] ?? '');
    
    $mes_referencia = "";
    $ano_referencia ="";
    
    // Processar upload do comprovativo (apenas salva o arquivo)
    $comprovativo_path = '';
    if (isset($_FILES['comprovativo']) && $_FILES['comprovativo']['error'] == 0 && $forma_pagamento != 'dinheiro') {
        $ext = pathinfo($_FILES['comprovativo']['name'], PATHINFO_EXTENSION);
        $comprovativo_nome = 'comprovativo_' . date('Ymd_His') . '_' . rand(1000, 9999) . '.' . $ext;
        $upload_dir = __DIR__ . '/uploads/comprovativos/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        move_uploaded_file($_FILES['comprovativo']['tmp_name'], $upload_dir . $comprovativo_nome);
        $comprovativo_path = 'uploads/comprovativos/' . $comprovativo_nome;
    }
    
    // Processar captura de ecrã
    if (isset($_POST['captura_base64']) && !empty($_POST['captura_base64']) && $forma_pagamento != 'dinheiro') {
        $captura_data = $_POST['captura_base64'];
        $captura_data = str_replace('data:image/jpeg;base64,', '', $captura_data);
        $captura_data = str_replace(' ', '+', $captura_data);
        $captura_conteudo = base64_decode($captura_data);
        $captura_nome = 'captura_' . date('Ymd_His') . '_' . rand(1000, 9999) . '.jpg';
        $upload_dir = __DIR__ . '/uploads/comprovativos/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        file_put_contents($upload_dir . $captura_nome, $captura_conteudo);
        $comprovativo_path = 'uploads/comprovativos/' . $captura_nome;
    }
    
    if (empty($cart)) {
        $error = "Carrinho vazio!";
    } elseif ($forma_pagamento != 'dinheiro' && empty($numero_referencia)) {
        $error = "O Nº de Referência/Comprovativo é obrigatório para esta forma de pagamento!";
    } else {
        try {
            $conn->beginTransaction();
            
            $numero_fatura = 'FT/' . date('Ymd') . '/' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            foreach ($cart as $item) {
                // Verificar mensalidade
                if ($item['tipo_pagamento_nome'] == 'mensalidade' && $item['mes_referencia'] > 0) {
                    
    $mes_referencia = trim($item['mes_referencia']);
    $ano_referencia = trim($item['ano_referencia']);
                    $sql_check_final = "SELECT id, valor_total, valor_pago, status FROM mensalidades 
                                        WHERE escola_id = ? AND aluno_id = ? 
                                        AND mes_referencia = ? AND ano_referencia = ?";
                    $stmt_check_final = $conn->prepare($sql_check_final);
                    $stmt_check_final->execute([
                        $escola_id, 
                        $item['aluno_id'], 
                        $item['mes_referencia'], 
                        $item['ano_referencia']
                    ]);
                    $mensalidade_final = $stmt_check_final->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$mensalidade_final) {
                        throw new Exception("Mensalidade não encontrada para o aluno " . $item['aluno_nome']);
                    }
                    if ($mensalidade_final['status'] == 'pago') {
                        throw new Exception("Mensalidade já foi totalmente paga para " . $item['aluno_nome']);
                    }
                    $valor_restante_final = $mensalidade_final['valor_total'] - ($mensalidade_final['valor_pago'] ?? 0);
                    if ($item['valor'] > $valor_restante_final) {
                        throw new Exception("Valor excede o restante da mensalidade de " . $item['aluno_nome']);
                    }
                }
                //$obs= $item['observacoes'] . ' | Quem pagou: ' . $item['quem_pagou'] . ' | Ref: ' . $numero_referencia . ' | Recebido por: ' . $quem_recebeu . ' | ' . $observacoes_finais;
                   
                
                // ============================================
                // INSERT CORRIGIDO - Usando SET com placeholders ?
                // ============================================
                $sql = "INSERT INTO pagamentos SET 
                        escola_id = ?,
                        assinatura_id = ?,
                        tipo_pagamento_id = ?,
                        tipo_pagamento = ?,
                        valor = ?,
                        metodo_pagamento = ?,
                        referente = ?,
                        observacoes = ?,
                        usuario_id = ?,
                        numero_fatura = ?,
                        numero_referencia = ?,
                        comprovativo_path = ?,
                        quem_recebeu = ?,
                        quem_pagou = ?,
                        status = 'confirmado',
                        data_pagamento = CURDATE(),
                        created_at = NOW()";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $escola_id,
                    $item['aluno_id'],
                    $item['tipo_pagamento_id'],
                    $item['tipo_pagamento_nome'].'-'.$mes_referencia.'-'.$ano_referencia,
                    $item['valor'],
                    $forma_pagamento,
                    !empty($item['referencia']) ? $item['referencia'] : $item['tipo_pagamento_nome'],
                    $item['observacoes'] . ' | Quem pagou: ' . $item['quem_pagou'] . ' | Ref: ' . $numero_referencia . ' | Recebido por: ' . $quem_recebeu . ' | ' . $observacoes_finais,
                    $usuario_id,
                    $numero_fatura,
                    $numero_referencia,
                    $comprovativo_path ,
                    $quem_recebeu,
                    $item['quem_pagou']
                ]);
                $pagamento_id = $conn->lastInsertId();
                
                // Atualizar mensalidade
                if ($item['tipo_pagamento_nome'] == 'mensalidade' && $item['mes_referencia'] > 0) {
                    $sql_update = "UPDATE mensalidades SET valor_pago = valor_pago + ?, status = CASE WHEN valor_pago + ? >= valor_total THEN 'pago' ELSE 'parcial' END, data_pagamento = CURDATE() 
                                   WHERE escola_id = ? AND aluno_id = ? AND mes_referencia = ? AND ano_referencia = ?";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->execute([
                        $item['valor'],
                        $item['valor'],
                        $escola_id,
                        $item['aluno_id'],
                        $item['mes_referencia'],
                        $item['ano_referencia']
                    ]);
                }

                   // Atualizar outros pagamentos
                if ($item['tipo_pagamento_nome'] != 'mensalidade') {
                    $sql_update = "UPDATE outros_pagamentos SET valor_pago = valor_pago + ?, status = CASE WHEN valor_pago + ? >= valor_total THEN 'pago' ELSE 'parcial' END, data_pagamento = CURDATE() 
                                   WHERE escola_id = ? AND aluno_id = ? AND ano_referencia = ? and valor_total!=0 and tipo_pagamento_id=?";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->execute([
                        $item['valor'],
                        $item['valor'],
                        $escola_id,
                        $item['aluno_id'],
                        $item['ano_referencia'],
                        $item['tipo_pagamento_id']
                    ]);
                }
                
                // Registrar no caixa
                $sql_caixa = "INSERT INTO caixa (escola_id, tipo, categoria, descricao, valor, metodo_pagamento, referencia, usuario_id, pagamento_id, data_movimento, status, created_at) 
                              VALUES (?, 'entrada', ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'ativo', NOW())";
                $stmt_caixa = $conn->prepare($sql_caixa);
                $stmt_caixa->execute([
                    $escola_id,
                    $item['tipo_pagamento_nome'],
                    "Pagamento de " . ucfirst($item['tipo_pagamento_nome']) . " - " . $item['aluno_nome'],
                    $item['valor'],
                    $forma_pagamento,
                    $numero_fatura,
                    $usuario_id,
                    $pagamento_id
                ]);
            }
            
            $conn->commit();
            
            unset($_SESSION['pagamento_cart']);
            $cart = [];
            
            $success = "Todos os pagamentos registrados com sucesso! Fatura Nº: $numero_fatura";
           
// Adicionar link para baixar comprovativo
$success .= '<br><br>';
$success .= '<div class="alert alert-success">';
$success .= '<i class="fas fa-check-circle"></i> Pagamentos registrados com sucesso!<br>';
$success .= '<strong>Fatura Nº: ' . $numero_fatura . '</strong><br><br>';
$success .= '<a href="baixar_comprovativo.php?fatura=' . urlencode($numero_fatura) . '" target="_blank" class="btn btn-sm btn-info">';
$success .= '<i class="fas fa-print"></i>Baixar Comprovativo';
$success .= '</a>';
$success .= '&nbsp;&nbsp;&nbsp;<a href="recibo_termico.php?fatura=' . urlencode($numero_fatura) . '" target="_blank" class="btn btn-sm btn-info">';
$success .= '<i class="fas fa-print"></i>Imprimir';
$success .= '</a>';

$success .= '</div>';
            
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
$sql_day = "SELECT COUNT(*) as total_pagamentos, COALESCE(SUM(valor), 0) as valor_total, COUNT(DISTINCT assinatura_id) as alunos_atendidos
            FROM pagamentos WHERE escola_id = :escola_id AND DATE(data_pagamento) = CURDATE()";
$stmt_day = $conn->prepare($sql_day);
$stmt_day->execute([':escola_id' => $escola_id]);
$stats_day = $stmt_day->fetch(PDO::FETCH_ASSOC);

$sql_month = "SELECT COUNT(*) as total_pagamentos, COALESCE(SUM(valor), 0) as valor_total, COUNT(DISTINCT assinatura_id) as alunos_atendidos, COALESCE(AVG(valor), 0) as ticket_medio
            FROM pagamentos WHERE escola_id = :escola_id AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())";
$stmt_month = $conn->prepare($sql_month);
$stmt_month->execute([':escola_id' => $escola_id]);
$stats_month = $stmt_month->fetch(PDO::FETCH_ASSOC);

$sql_type = "SELECT tipo_pagamento, COUNT(*) as quantidade, COALESCE(SUM(valor), 0) as total
            FROM pagamentos WHERE escola_id = :escola_id AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE()) GROUP BY tipo_pagamento";
$stmt_type = $conn->prepare($sql_type);
$stmt_type->execute([':escola_id' => $escola_id]);
$stats_by_type = $stmt_type->fetchAll(PDO::FETCH_ASSOC);

$sql_method = "SELECT metodo_pagamento, COUNT(*) as quantidade, COALESCE(SUM(valor), 0) as total
            FROM pagamentos WHERE escola_id = :escola_id AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE()) GROUP BY metodo_pagamento ORDER BY total DESC";
$stmt_method = $conn->prepare($sql_method);
$stmt_method->execute([':escola_id' => $escola_id]);
$stats_by_method = $stmt_method->fetchAll(PDO::FETCH_ASSOC);

$sql_daily = "SELECT DAY(data_pagamento) as dia, COALESCE(SUM(valor), 0) as total
            FROM pagamentos WHERE escola_id = :escola_id AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE()) GROUP BY DAY(data_pagamento) ORDER BY dia ASC";
$stmt_daily = $conn->prepare($sql_daily);
$stmt_daily->execute([':escola_id' => $escola_id]);
$stats_daily = $stmt_daily->fetchAll(PDO::FETCH_ASSOC);

$sql_debts = "SELECT COUNT(*) as total_debitos, COALESCE(SUM(valor_total - COALESCE(valor_pago, 0)), 0) as valor_total_devedor
            FROM mensalidades WHERE escola_id = :escola_id AND status IN ('pendente', 'parcial')";
$stmt_debts = $conn->prepare($sql_debts);
$stmt_debts->execute([':escola_id' => $escola_id]);
$stats_debts = $stmt_debts->fetch(PDO::FETCH_ASSOC);

$meta_mensal = 500000;
$percentual_meta = ($stats_month['valor_total'] > 0) ? ($stats_month['valor_total'] / $meta_mensal) * 100 : 0;

// ============================================
// BUSCAR DADOS COM PAGINAÇÃO
// ============================================

// Buscar alunos
$sql_alunos = "SELECT e.id, e.nome, e.matricula FROM estudantes e WHERE e.escola_id = :escola_id AND e.status = 'ativo' ORDER BY e.nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar débitos COM PAGINAÇÃO
$sql_debitos = "SELECT m.*, e.nome as aluno_nome, e.matricula 
                FROM mensalidades m 
                JOIN estudantes e ON e.id = m.aluno_id 
                WHERE m.escola_id = :escola_id 
                AND m.status IN ('pendente', 'parcial') 
                ORDER BY e.nome ASC, m.ano_referencia DESC, m.mes_referencia ASC 
                LIMIT :offset, :limit";

$stmt_debitos = $conn->prepare($sql_debitos);
$offset_debitos_int = (int)$offset_debitos;
$limit_debitos_int = (int)$registros_debitos_por_pagina;
$stmt_debitos->bindParam(':escola_id', $escola_id, PDO::PARAM_INT);
$stmt_debitos->bindParam(':offset', $offset_debitos_int, PDO::PARAM_INT);
$stmt_debitos->bindParam(':limit', $limit_debitos_int, PDO::PARAM_INT);
$stmt_debitos->execute();
$debitos = $stmt_debitos->fetchAll(PDO::FETCH_ASSOC);

// Contar total de débitos
$sql_total_debitos = "SELECT COUNT(*) as total FROM mensalidades m WHERE m.escola_id = :escola_id AND m.status IN ('pendente', 'parcial')";
$stmt_total_debitos = $conn->prepare($sql_total_debitos);
$stmt_total_debitos->bindParam(':escola_id', $escola_id, PDO::PARAM_INT);
$stmt_total_debitos->execute();
$total_debitos = $stmt_total_debitos->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas_debitos = ceil($total_debitos / $registros_debitos_por_pagina);

// Buscar últimos pagamentos COM PAGINAÇÃO
$sql_ultimos = "SELECT p.*, e.nome as aluno_nome, e.matricula 
                FROM pagamentos p 
                JOIN estudantes e ON e.id = p.assinatura_id 
                WHERE p.escola_id = :escola_id 
                ORDER BY p.id DESC 
                LIMIT :offset, :limit";

$stmt_ultimos = $conn->prepare($sql_ultimos);
$offset_ultimos_int = (int)$offset_ultimos;
$limit_ultimos_int = (int)$registros_ultimos_por_pagina;
$stmt_ultimos->bindParam(':escola_id', $escola_id, PDO::PARAM_INT);
$stmt_ultimos->bindParam(':offset', $offset_ultimos_int, PDO::PARAM_INT);
$stmt_ultimos->bindParam(':limit', $limit_ultimos_int, PDO::PARAM_INT);
$stmt_ultimos->execute();
$ultimos_pagamentos = $stmt_ultimos->fetchAll(PDO::FETCH_ASSOC);

// Contar total de pagamentos
$sql_total_ultimos = "SELECT COUNT(*) as total FROM pagamentos WHERE escola_id = :escola_id";
$stmt_total_ultimos = $conn->prepare($sql_total_ultimos);
$stmt_total_ultimos->bindParam(':escola_id', $escola_id, PDO::PARAM_INT);
$stmt_total_ultimos->execute();
$total_ultimos = $stmt_total_ultimos->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas_ultimos = ceil($total_ultimos / $registros_ultimos_por_pagina);

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
        .main-content-tesouraria { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content-tesouraria { margin-left: 0; } }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-success { background: #28a745; border: none; }
        .btn-success:hover { background: #218838; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; position: relative; overflow: hidden; }
        .stat-card h3 { font-size: 2rem; margin: 0; font-weight: bold; }
        .stat-card p { margin: 0; opacity: 0.9; }
        .stat-card .icon { font-size: 3rem; opacity: 0.3; position: absolute; right: 20px; top: 20px; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .table-pagamentos td { vertical-align: middle; }
        .debito-row { background-color: #fff3cd; }
        .valor-debito { color: #dc3545; font-weight: bold; }
        .info-aluno { font-size: 0.85rem; color: #6c757d; }
        .cart-item { background: #f8f9fa; border-radius: 10px; padding: 10px; margin-bottom: 10px; }
        .cart-total { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; border-radius: 10px; padding: 15px; margin-top: 15px; }
        .pagination { margin-top: 20px; }
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
        
        <!-- GRÁFICOS -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-bar"></i> Arrecadação Diária - <?php echo date('F/Y'); ?></h5></div>
                    <div class="card-body"><canvas id="dailyChart" height="150"></canvas></div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-pie"></i> Formas de Pagamento</h5></div>
                    <div class="card-body">
                        <canvas id="methodChart" height="50"></canvas>
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
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-pie"></i> Distribuição por Tipo</h5></div>
                    <div class="card-body">
                        <canvas id="typeChart" height="150"></canvas>
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
        
        <!-- Dívidas em Aberto com Paginação -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Dívidas em Aberto</h5>
                <small>Total: <?php echo number_format($total_debitos); ?> mensalidade(s) pendente(s)</small>
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
                                <tr><th>Aluno</th><th>Mês/Ano</th><th>Valor Total</th><th>Valor Pago</th><th>Saldo Devedor</th><th>Status</th><th>Ação</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($debitos as $debito): ?>
                                <tr class="debito-row">
                                    <td><strong><?php echo htmlspecialchars($debito['aluno_nome']); ?></strong><br><small class="info-aluno">Mat: <?php echo htmlspecialchars($debito['matricula'] ?? ''); ?></small></td>
                                    <td><?php echo getMesNome($debito['mes_referencia']) . '/' . $debito['ano_referencia']; ?></td>
                                    <td><?php echo formatarMoeda($debito['valor_total']); ?></td>
                                    <td><?php echo formatarMoeda($debito['valor_pago'] ?? 0); ?></td>
                                    <td class="valor-debito"><?php echo formatarMoeda(($debito['valor_total'] - ($debito['valor_pago'] ?? 0))); ?></td>
                                    <td><?php echo $debito['status'] == 'pendente' ? '<span class="badge bg-danger">Pendente</span>' : '<span class="badge bg-warning text-dark">Parcial</span>'; ?></td>
                                    <td><button class="btn btn-sm btn-success" onclick="adicionarAoCarrinhoRapido(<?php echo $debito['aluno_id']; ?>, '<?php echo htmlspecialchars($debito['aluno_nome']); ?>', '<?php echo htmlspecialchars($debito['matricula']); ?>', <?php echo $debito['valor_total'] - ($debito['valor_pago'] ?? 0); ?>, <?php echo $debito['mes_referencia']; ?>, <?php echo $debito['ano_referencia']; ?>)"><i class="fas fa-cart-plus"></i> Pagar</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($total_paginas_debitos > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $pagina_debitos <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina_debitos=<?php echo $pagina_debitos - 1; ?><?php echo isset($_GET['pagina']) ? '&pagina=' . $_GET['pagina'] : ''; ?>"><i class="fas fa-chevron-left"></i> Anterior</a>
                            </li>
                            <?php for($i = 1; $i <= $total_paginas_debitos; $i++): ?>
                            <li class="page-item <?php echo $pagina_debitos == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?pagina_debitos=<?php echo $i; ?><?php echo isset($_GET['pagina']) ? '&pagina=' . $_GET['pagina'] : ''; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $pagina_debitos >= $total_paginas_debitos ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina_debitos=<?php echo $pagina_debitos + 1; ?><?php echo isset($_GET['pagina']) ? '&pagina=' . $_GET['pagina'] : ''; ?>">Próxima <i class="fas fa-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Últimos Pagamentos com Paginação -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Últimos Pagamentos</h5>
                <small>Total: <?php echo number_format($total_ultimos); ?> pagamento(s)</small>
            </div>
            <div class="card-body">
                <?php if (empty($ultimos_pagamentos)): ?>
                    <div class="alert alert-info text-center">Nenhum pagamento registrado ainda.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-pagamentos">
                            <thead class="table-light">
                                <tr><th>Data</th><th>Aluno</th><th>Tipo</th><th>Valor</th><th>Forma</th><th>Quem Pagou</th><th>Referência</th></td>
                            </thead>
                            <tbody>
                                <?php foreach ($ultimos_pagamentos as $pg): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($pg['aluno_nome']); ?></strong><br><small class="text-muted">Mat: <?php echo htmlspecialchars($pg['matricula'] ?? ''); ?></small></td>
                                    <td><?php echo getTipoPagamentoLabel($pg['tipo_pagamento']); ?></td>
                                    <td class="text-success fw-bold"><?php echo formatarMoeda($pg['valor']); ?></td>
                                    <td><?php echo getFormaPagamentoIcone($pg['metodo_pagamento']); ?></td>
                                    <td><?php echo htmlspecialchars($pg['quem_pagou'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($pg['numero_referencia'] ?: '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($total_paginas_ultimos > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $pagina_ultimos <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina_ultimos - 1; ?><?php echo isset($_GET['pagina_debitos']) ? '&pagina_debitos=' . $_GET['pagina_debitos'] : ''; ?>"><i class="fas fa-chevron-left"></i> Anterior</a>
                            </li>
                            <?php for($i = 1; $i <= $total_paginas_ultimos; $i++): ?>
                            <li class="page-item <?php echo $pagina_ultimos == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $i; ?><?php echo isset($_GET['pagina_debitos']) ? '&pagina_debitos=' . $_GET['pagina_debitos'] : ''; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $pagina_ultimos >= $total_paginas_ultimos ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina_ultimos + 1; ?><?php echo isset($_GET['pagina_debitos']) ? '&pagina_debitos=' . $_GET['pagina_debitos'] : ''; ?>">Próxima <i class="fas fa-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
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
                <form method="POST" id="formAddCarrinho">
                    <div class="modal-body">
                        <div class="alert alert-info"><i class="fas fa-info-circle"></i> Adicione itens ao carrinho. No final, clique em "Finalizar Carrinho" para registrar todos os pagamentos.</div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Aluno <span class="text-danger">*</span></label>
                                <select name="aluno_id" id="aluno_id" class="form-select" required>
                                    <option value="">Selecione um aluno</option>
                                    <?php foreach ($alunos as $aluno): ?>
                                    <option value="<?php echo $aluno['id']; ?>" data-matricula="<?php echo $aluno['matricula']; ?>">
                                        <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Tipo de Pagamento <span class="text-danger">*</span></label>
                                <select name="tipo_pagamento_id" id="tipo_pagamento_select" class="form-select" required onchange="toggleMensalidadeSelect()">
                                    <option value="">Selecione um tipo de pagamento</option>
                                    <?php foreach ($tipos_pagamento_db as $tipo): ?>
                                    <option value="<?php echo $tipo['id']; ?>" data-nome="<?php echo strtolower($tipo['nome']); ?>">
                                        <i class="<?php echo $tipo['icone']; ?>"></i> <?php echo ucfirst($tipo['nome']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="tipo_pagamento_nome" id="tipo_pagamento_nome" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valor <span class="text-danger">*</span></label>
                                <input type="text" name="valor" id="valor" class="form-control" placeholder="0,00" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quem está a pagar?</label>
                                <input type="text" name="quem_pagou" class="form-control" placeholder="Ex: Pai, Mãe, Responsável, Próprio aluno...">
                            </div>
                        </div>
                        
                        <div class="row" id="div_mensalidade" style="display: none;">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mês Referência <span class="text-danger">*</span></label>
                                <select name="mes_referencia" id="mes_referencia" class="form-select">
                                    <option value="">Selecione o mês</option>
                                    <?php for($i=1;$i<=12;$i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == date('m') ? 'selected' : ''; ?>>
                                        <?php echo getMesNome($i); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ano Referência <span class="text-danger">*</span></label>
                                <select name="ano_referencia" id="ano_referencia" class="form-select">
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
                        <input type="hidden" name="aluno_matricula" id="aluno_matricula_hidden">
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
                <form method="POST" id="formFinalizar" enctype="multipart/form-data">
                    <div class="modal-body">
                        <?php if (empty($cart)): ?>
                            <div class="alert alert-warning text-center">Carrinho vazio!</div>
                        <?php else: ?>
                            <?php $subtotal = 0; foreach ($cart as $index => $item): $subtotal += $item['valor']; ?>
                            <div class="cart-item">
                                <div class="row align-items-center">
                                    <div class="col-md-5">
                                        <strong><?php echo htmlspecialchars($item['aluno_nome']); ?></strong><br>
                                        <small class="text-muted">Mat: <?php echo $item['aluno_matricula']; ?></small><br>
                                        <small class="text-muted"><?php echo ucfirst($item['tipo_pagamento_nome']); ?></small>
                                        <?php if (!empty($item['quem_pagou'])): ?>
                                        <br><small>Quem pagou: <?php echo htmlspecialchars($item['quem_pagou']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4"><span class="text-muted"><?php echo htmlspecialchars($item['referencia'] ?: '-'); ?></span></div>
                                    <div class="col-md-2 text-end"><strong class="text-success"><?php echo formatarMoeda($item['valor']); ?></strong></div>
                                    <div class="col-md-1">
                                        <button type="submit" name="remove_from_cart" class="btn btn-sm btn-danger" onclick="this.form.cart_index.value=<?php echo $index; ?>"><i class="fas fa-trash"></i></button>
                                        <input type="hidden" name="cart_index" value="">
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <div class="cart-total">
                                <div class="row">
                                    <div class="col-md-6"><strong>Total de Itens:</strong> <?php echo count($cart); ?><br><strong>Valor Total:</strong></div>
                                    <div class="col-md-6 text-end"><h4 class="mb-0"><?php echo formatarMoeda($subtotal); ?></h4></div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <label class="form-label">Forma de Pagamento <span class="text-danger">*</span></label>
                                <select name="forma_pagamento" id="forma_pagamento_select" class="form-select" required onchange="toggleCamposComprovativo()">
                                    <option value="dinheiro">💵 Dinheiro</option>
                                    <option value="transferencia">🏦 Transferência Bancária</option>
                                    <option value="deposito">💰 Depósito</option>
                                    <option value="cheque">📄 Cheque</option>
                                    <option value="multicaixa">💳 Multicaixa</option>
                                </select>
                            </div>
                            
                            <div id="div_comprovativo" style="display: none;">
                                <div class="mt-3">
                                    <label class="form-label">Nº de Referência / Comprovativo <span class="text-danger">*</span></label>
                                    <input type="text" name="numero_referencia" id="numero_referencia" class="form-control" placeholder="Ex: Transferência: 123456789, Depósito: 001234">
                                    <small class="text-muted">Informe o número de referência do comprovativo de pagamento</small>
                                </div>
                                
                                <div class="mt-3">
                                    <label class="form-label">Comprovativo de Pagamento (Opcional)</label>
                                    <input type="file" name="comprovativo" id="comprovativo" class="form-control" accept="image/*,.pdf">
                                    <small class="text-muted">Envie uma foto ou PDF do comprovativo (máx 5MB)</small>
                                </div>
                                
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-info" id="btnAbrirCaptura">
                                        <i class="fas fa-camera-retro"></i> Capturar Comprovativo (Webcam)
                                    </button>
                                </div>
                                
                                <div id="div_captura" class="mt-3" style="display: none;">
                                    <div class="border rounded p-3 text-center">
                                        <video id="video" width="100%" height="auto" autoplay style="max-height: 200px;"></video>
                                        <canvas id="canvas" style="display: none;"></canvas>
                                        <button type="button" class="btn btn-sm btn-primary mt-2" id="btnCapturar"><i class="fas fa-camera"></i> Capturar</button>
                                        <button type="button" class="btn btn-sm btn-danger mt-2" id="btnFecharCamera"><i class="fas fa-times"></i> Fechar</button>
                                        <div id="previewCaptura" class="mt-2" style="display: none;">
                                            <img id="capturaImg" src="" style="max-width: 100%; max-height: 150px;">
                                            <input type="hidden" name="captura_base64" id="captura_base64">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <label class="form-label">Recebido por / Atendente <span class="text-danger">*</span></label>
                                <input type="text" name="quem_recebeu" class="form-control" value="<?php echo $usuario_nome; ?>" required disabled>
                            </div>
                            
                            <div class="mt-3">
                                <label class="form-label">Observações Finais</label>
                                <textarea name="observacoes_finais" class="form-control" rows="2" placeholder="Observações adicionais sobre este pagamento..."></textarea>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <?php if (!empty($cart)): ?>
                        <button type="submit" name="clear_cart" class="btn btn-secondary" onclick="return confirm('Limpar carrinho?')"><i class="fas fa-trash-alt"></i> Limpar</button>
                        <button type="submit" name="finalizar_pagamentos" class="btn btn-success"><i class="fas fa-check-circle"></i> Finalizar Pagamentos</button>
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
        
        $('#valor').on('input', function() { $(this).val(formatarValor($(this).val())); });
        
        function toggleMensalidadeSelect() {
            let tipoSelect = $('#tipo_pagamento_select');
            let tipoId = tipoSelect.val();
            let tipoNome = tipoSelect.find('option:selected').data('nome');
            
            $('#tipo_pagamento_nome').val(tipoNome);
            
            if (tipoNome === 'mensalidade' || tipoId == '1') {
                $('#div_mensalidade').show();
                $('#mes_referencia').prop('required', true);
                $('#ano_referencia').prop('required', true);
            } else {
                $('#div_mensalidade').hide();
                $('#mes_referencia').prop('required', false);
                $('#ano_referencia').prop('required', false);
            }
        }
        
        function toggleCamposComprovativo() {
            let formaPagamento = $('#forma_pagamento_select').val();
            if (formaPagamento === 'dinheiro') {
                $('#div_comprovativo').hide();
                $('#numero_referencia').prop('required', false);
            } else {
                $('#div_comprovativo').show();
                $('#numero_referencia').prop('required', true);
            }
        }
        
        $('#aluno_id').on('change', function() {
            let nome = $(this).find('option:selected').text();
            let matricula = $(this).find('option:selected').data('matricula');
            $('#aluno_nome_hidden').val(nome);
            $('#aluno_matricula_hidden').val(matricula);
        });
        
        $('#formAddCarrinho').on('submit', function(e) {
            let tipoNome = $('#tipo_pagamento_nome').val();
            let mes = $('#mes_referencia').val();
            let ano = $('#ano_referencia').val();
            
            if (tipoNome === 'mensalidade') {
                if (!mes || mes === '') {
                    e.preventDefault();
                    alert('Por favor, selecione o MÊS de referência da mensalidade!');
                    $('#mes_referencia').focus();
                    return false;
                }
                if (!ano || ano === '') {
                    e.preventDefault();
                    alert('Por favor, selecione o ANO de referência da mensalidade!');
                    $('#ano_referencia').focus();
                    return false;
                }
            }
            return true;
        });
        
        function adicionarAoCarrinhoRapido(alunoId, alunoNome, alunoMatricula, valor, mes, ano) {
            $('#aluno_id').val(alunoId);
            $('#aluno_nome_hidden').val(alunoNome);
            $('#aluno_matricula_hidden').val(alunoMatricula);
            $('#valor').val(formatarValor(valor.toString()));
            
            if (mes && mes > 0) {
                $('#mes_referencia').val(mes);
            } else {
                $('#mes_referencia').val(new Date().getMonth() + 1);
            }
            
            if (ano && ano > 0) {
                $('#ano_referencia').val(ano);
            } else {
                $('#ano_referencia').val(new Date().getFullYear());
            }
            
            $('#tipo_pagamento_select').val('1').trigger('change');
            $('#modalNovoPagamento').modal('show');
        }
        
        let stream = null;
        let video = document.getElementById('video');
        let canvas = document.getElementById('canvas');
        
        $('#btnAbrirCaptura').on('click', function() {
            $('#div_captura').toggle();
            if ($('#div_captura').is(':visible')) {
                if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    navigator.mediaDevices.getUserMedia({ video: true })
                        .then(function(mediaStream) {
                            stream = mediaStream;
                            video.srcObject = mediaStream;
                            video.play();
                            $('#btnCapturar').show();
                        }).catch(function(err) { alert("Erro ao aceder à câmara: " + err); });
                }
            } else if (stream) {
                stream.getTracks().forEach(track => track.stop());
                video.srcObject = null;
                stream = null;
            }
        });
        
        $('#btnCapturar').on('click', function() {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            let dataURL = canvas.toDataURL('image/jpeg', 0.8);
            $('#capturaImg').attr('src', dataURL);
            $('#captura_base64').val(dataURL);
            $('#previewCaptura').show();
            if (stream) { stream.getTracks().forEach(track => track.stop()); video.srcObject = null; stream = null; }
            $('#btnCapturar').hide();
        });
        
        $('#btnFecharCamera').on('click', function() {
            if (stream) { stream.getTracks().forEach(track => track.stop()); video.srcObject = null; stream = null; }
            $('#div_captura').hide();
        });
        
        $('#forma_pagamento_select').on('change', toggleCamposComprovativo);
        toggleCamposComprovativo();
        
        $(document).ready(function() {
            toggleMensalidadeSelect();
            if ($('#mes_referencia').val() === '') {
                $('#mes_referencia').val(new Date().getMonth() + 1);
            }
            if ($('#ano_referencia').val() === '') {
                $('#ano_referencia').val(new Date().getFullYear());
            }
        });
        
        // GRÁFICOS
        const dailyData = <?php $days = array_fill(1, date('t'), 0); foreach ($stats_daily as $d) { $days[$d['dia']] = $d['total']; } echo json_encode(array_values($days)); ?>;
        new Chart(document.getElementById('dailyChart'), { type: 'bar', data: { labels: <?php echo json_encode(range(1, date('t'))); ?>, datasets: [{ label: 'Arrecadação (Kz)', data: dailyData, backgroundColor: 'rgba(0,107,62,0.6)', borderColor: '#006B3E', borderWidth: 1 }] }, options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return 'Kz ' + v.toLocaleString(); } } } } } });
        
        new Chart(document.getElementById('typeChart'), { type: 'doughnut', data: { labels: <?php echo json_encode(array_column($stats_by_type, 'tipo_pagamento')); ?>, datasets: [{ data: <?php echo json_encode(array_column($stats_by_type, 'total')); ?>, backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#ffc107', '#6c757d'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } } });
        
        new Chart(document.getElementById('methodChart'), { type: 'pie', data: { labels: <?php echo json_encode(array_column($stats_by_method, 'metodo_pagamento')); ?>, datasets: [{ data: <?php echo json_encode(array_column($stats_by_method, 'total')); ?>, backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6c757d'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } } });
        
        $('#tipo_pagamento_select').on('change', toggleMensalidadeSelect);
    </script>
</body>
</html>