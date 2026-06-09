<?php
// escola/professor/dividas_pagar.php - Gerir Dívidas do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR DADOS DO PROFESSOR
// ============================================
$sql_professor = "
    SELECT p.*, u.email, u.nome, f.id as funcionario_id, f.salario_base
    FROM funcionarios p
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    LEFT JOIN funcionarios f ON f.usuario_id = p.usuario_id
    WHERE p.id = :professor_id
";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':professor_id' => $professor_id]);
$professor_dados = $stmt_professor->fetch(PDO::FETCH_ASSOC);

$funcionario_id = $professor_dados['funcionario_id'] ?? 0;

if (!$funcionario_id) {
    die('<div class="alert alert-danger">Erro: Funcionário não encontrado. Contacte a administração.</div>');
}

// ============================================
// FUNÇÃO PARA VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================
function criarTabelasNecessarias($conn) {
    try {
        // Tabela de dívidas
        $sql_dividas = "CREATE TABLE IF NOT EXISTS dividas_funcionarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            funcionario_id INT NOT NULL,
            valor DECIMAL(12,2) NOT NULL,
            valor_pago DECIMAL(12,2) DEFAULT 0,
            descricao TEXT,
            tipo ENUM('emprestimo', 'multa', 'adiantamento', 'outro') DEFAULT 'outro',
            data_vencimento DATE,
            status ENUM('pendente', 'parcial', 'pago', 'cancelado') DEFAULT 'pendente',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_funcionario (funcionario_id),
            INDEX idx_status (status),
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->exec($sql_dividas);
        
        // Tabela de parcelas
        $sql_parcelas = "CREATE TABLE IF NOT EXISTS parcelas_emprestimo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            divida_id INT NOT NULL,
            numero_parcela INT NOT NULL,
            valor_parcela DECIMAL(12,2) NOT NULL,
            data_vencimento DATE,
            mes_competencia INT,
            ano_competencia INT,
            status ENUM('pendente', 'pago', 'cancelado') DEFAULT 'pendente',
            data_pagamento DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_divida (divida_id),
            INDEX idx_status (status),
            FOREIGN KEY (divida_id) REFERENCES dividas_funcionarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->exec($sql_parcelas);
        
        // Tabela de pagamentos
        $sql_pagamentos = "CREATE TABLE IF NOT EXISTS pagamentos_dividas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            divida_id INT NOT NULL,
            parcela_id INT NULL,
            valor_pago DECIMAL(12,2) NOT NULL,
            forma_pagamento ENUM('dinheiro', 'transferencia', 'desconto_folha', 'cheque') DEFAULT 'dinheiro',
            observacao TEXT,
            data_pagamento DATETIME,
            comprovante VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_divida (divida_id),
            FOREIGN KEY (divida_id) REFERENCES dividas_funcionarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->exec($sql_pagamentos);
        
        return true;
    } catch (PDOException $e) {
        error_log("Erro ao criar tabelas: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNÇÃO PARA PROCESSAR DESCONTO AUTOMÁTICO NA FOLHA
// ============================================
function processarDescontoAutomaticoFolha($conn, $funcionario_id, $mes, $ano, $valor_desconto, $descricao) {
    try {
        // Buscar registro da folha para o período
        $sql_busca = "SELECT id, total_vencimentos, total_descontos, salario_liquido, outros_descontos 
                      FROM folha_processamento_funcionarios 
                      WHERE funcionario_id = :funcionario_id 
                      AND mes_competencia = :mes 
                      AND ano_competencia = :ano
                      LIMIT 1";
        
        $stmt_busca = $conn->prepare($sql_busca);
        $stmt_busca->execute([
            ':funcionario_id' => $funcionario_id,
            ':mes' => $mes,
            ':ano' => $ano
        ]);
        
        $folha = $stmt_busca->fetch(PDO::FETCH_ASSOC);
        
        if ($folha) {
            // Atualizar registro existente
            $novo_total_descontos = $folha['total_descontos'] + $valor_desconto;
            $novo_salario_liquido = $folha['total_vencimentos'] - $novo_total_descontos;
            $novo_outros_descontos = ($folha['outros_descontos'] ?? 0) + $valor_desconto;
            
            $sql_update = "UPDATE folha_processamento_funcionarios 
                           SET total_descontos = :total_descontos,
                               salario_liquido = :salario_liquido,
                               outros_descontos = :outros_descontos,
                               observacoes = CASE 
                                   WHEN observacoes IS NULL OR observacoes = '' THEN :descricao
                                   ELSE CONCAT(observacoes, CHAR(10), CHAR(13), :descricao)
                               END,
                               updated_at = NOW()
                           WHERE id = :id";
            
            $stmt_update = $conn->prepare($sql_update);
            return $stmt_update->execute([
                ':total_descontos' => $novo_total_descontos,
                ':salario_liquido' => $novo_salario_liquido,
                ':outros_descontos' => $novo_outros_descontos,
                ':descricao' => date('d/m/Y H:i:s') . ' - ' . $descricao,
                ':id' => $folha['id']
            ]);
        } else {
            // Buscar dados do funcionário
            $sql_func = "SELECT f.salario_base, f.escola_id, f.usuario_id 
                        FROM funcionarios f 
                        WHERE f.id = :funcionario_id";
            $stmt_func = $conn->prepare($sql_func);
            $stmt_func->execute([':funcionario_id' => $funcionario_id]);
            $func = $stmt_func->fetch(PDO::FETCH_ASSOC);
            
            if (!$func) {
                error_log("Funcionário não encontrado: ID $funcionario_id");
                return false;
            }
            
            // Buscar ano letivo ativo
            $sql_ano = "SELECT id FROM ano_letivo WHERE ativo = 1 LIMIT 1";
            $stmt_ano = $conn->query($sql_ano);
            $ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
            $ano_letivo_id = $ano_letivo['id'] ?? 1;
            
            $salario_base = $func['salario_base'] ?? 0;
            $total_vencimentos = $salario_base;
            $total_descontos = $valor_desconto;
            $salario_liquido = $total_vencimentos - $total_descontos;
            
            // Inserir novo registro
            $sql_insert = "INSERT INTO folha_processamento_funcionarios 
                           (funcionario_id, escola_id, ano_letivo_id, mes_competencia, ano_competencia,
                            salario_base, total_vencimentos, outros_descontos, total_descontos, 
                            salario_liquido, status, observacoes, data_processamento, created_at)
                           VALUES 
                           (:funcionario_id, :escola_id, :ano_letivo_id, :mes, :ano,
                            :salario_base, :total_vencimentos, :outros_descontos, :total_descontos,
                            :salario_liquido, 'processado', :descricao, NOW(), NOW())";
            
            $stmt_insert = $conn->prepare($sql_insert);
            $result = $stmt_insert->execute([
                ':funcionario_id' => $funcionario_id,
                ':escola_id' => $func['escola_id'],
                ':ano_letivo_id' => $ano_letivo_id,
                ':mes' => $mes,
                ':ano' => $ano,
                ':salario_base' => $salario_base,
                ':total_vencimentos' => $total_vencimentos,
                ':outros_descontos' => $valor_desconto,
                ':total_descontos' => $total_descontos,
                ':salario_liquido' => $salario_liquido,
                ':descricao' => date('d/m/Y H:i:s') . ' - ' . $descricao
            ]);
            
            if (!$result) {
                $error = $stmt_insert->errorInfo();
                error_log("Erro ao inserir folha: " . print_r($error, true));
                return false;
            }
            
            return true;
        }
    } catch (PDOException $e) {
        error_log("Erro ao processar desconto: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNÇÃO PARA REGISTRAR NOVA DÍVIDA
// ============================================
function registrarDivida($conn, $funcionario_id, $valor, $descricao, $tipo, $data_vencimento) {
    try {
        $sql = "INSERT INTO dividas_funcionarios 
                (funcionario_id, valor, valor_pago, descricao, tipo, data_vencimento, status, created_at)
                VALUES 
                (:funcionario_id, :valor, 0, :descricao, :tipo, :data_vencimento, 'pendente', NOW())";
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->execute([
            ':funcionario_id' => $funcionario_id,
            ':valor' => $valor,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':data_vencimento' => $data_vencimento
        ]);
        
        return $result ? $conn->lastInsertId() : false;
    } catch (PDOException $e) {
        error_log("Erro ao registrar dívida: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNÇÃO PARA PROCESSAR PAGAMENTO DE DÍVIDA
// ============================================
function processarPagamentoDivida($conn, $divida_id, $valor_pago, $forma_pagamento, $observacao) {
    try {
        $conn->beginTransaction();
        
        // Buscar dívida
        $sql_busca = "SELECT * FROM dividas_funcionarios WHERE id = :id FOR UPDATE";
        $stmt_busca = $conn->prepare($sql_busca);
        $stmt_busca->execute([':id' => $divida_id]);
        $divida = $stmt_busca->fetch(PDO::FETCH_ASSOC);
        
        if (!$divida) {
            throw new Exception("Dívida não encontrada");
        }
        
        $novo_valor_pago = $divida['valor_pago'] + $valor_pago;
        $novo_status = ($novo_valor_pago >= $divida['valor']) ? 'pago' : 'parcial';
        
        // Atualizar dívida
        $sql_update = "UPDATE dividas_funcionarios 
                       SET valor_pago = :valor_pago,
                           status = :status,
                           updated_at = NOW()
                       WHERE id = :id";
        
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([
            ':valor_pago' => $novo_valor_pago,
            ':status' => $novo_status,
            ':id' => $divida_id
        ]);
        
        // Registrar pagamento
        $sql_pagamento = "INSERT INTO pagamentos_dividas 
                          (divida_id, valor_pago, forma_pagamento, observacao, data_pagamento, created_at)
                          VALUES 
                          (:divida_id, :valor_pago, :forma_pagamento, :observacao, NOW(), NOW())";
        
        $stmt_pagamento = $conn->prepare($sql_pagamento);
        $stmt_pagamento->execute([
            ':divida_id' => $divida_id,
            ':valor_pago' => $valor_pago,
            ':forma_pagamento' => $forma_pagamento,
            ':observacao' => $observacao
        ]);
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Erro ao processar pagamento: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNÇÃO PARA MARCAR PARCELA COMO PAGA
// ============================================
function marcarParcelaPaga($conn, $parcela_id, $forma_pagamento, $observacao) {
    try {
        $conn->beginTransaction();
        
        // Buscar parcela
        $sql_parcela = "SELECT * FROM parcelas_emprestimo WHERE id = :id";
        $stmt_parcela = $conn->prepare($sql_parcela);
        $stmt_parcela->execute([':id' => $parcela_id]);
        $parcela = $stmt_parcela->fetch(PDO::FETCH_ASSOC);
        
        if (!$parcela) {
            throw new Exception("Parcela não encontrada");
        }
        
        // Marcar parcela como paga
        $sql_update = "UPDATE parcelas_emprestimo 
                       SET status = 'pago', data_pagamento = NOW()
                       WHERE id = :id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([':id' => $parcela_id]);
        
        // Registrar pagamento
        $sql_pagamento = "INSERT INTO pagamentos_dividas 
                          (divida_id, parcela_id, valor_pago, forma_pagamento, observacao, data_pagamento, created_at)
                          VALUES 
                          (:divida_id, :parcela_id, :valor_pago, :forma_pagamento, :observacao, NOW(), NOW())";
        
        $stmt_pagamento = $conn->prepare($sql_pagamento);
        $stmt_pagamento->execute([
            ':divida_id' => $parcela['divida_id'],
            ':parcela_id' => $parcela_id,
            ':valor_pago' => $parcela['valor_parcela'],
            ':forma_pagamento' => $forma_pagamento,
            ':observacao' => $observacao
        ]);
        
        // Atualizar total pago da dívida
        $sql_total = "UPDATE dividas_funcionarios 
                      SET valor_pago = (SELECT SUM(valor_parcela) FROM parcelas_emprestimo WHERE divida_id = :divida_id AND status = 'pago'),
                          status = CASE 
                              WHEN (SELECT SUM(valor_parcela) FROM parcelas_emprestimo WHERE divida_id = :divida_id AND status = 'pago') >= valor THEN 'pago'
                              ELSE 'parcial'
                          END
                      WHERE id = :divida_id";
        
        $stmt_total = $conn->prepare($sql_total);
        $stmt_total->execute([':divida_id' => $parcela['divida_id']]);
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Erro ao marcar parcela paga: " . $e->getMessage());
        return false;
    }
}

// ============================================
// PROCESSAR AÇÕES DO FORMULÁRIO
// ============================================
$mensagem = '';
$erro = '';

// Criar tabelas se necessário
criarTabelasNecessarias($conn);

// Processar novo empréstimo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'novo_emprestimo') {
    $valor = (float)$_POST['valor'];
    $descricao = $_POST['descricao'] ?? 'Empréstimo solicitado';
    $parcelas = (int)$_POST['parcelas'];
    $data_primeira_parcela = $_POST['data_primeira_parcela'];
    $desconto_folha = isset($_POST['desconto_folha']) ? 1 : 0;
    
    if ($valor > 0 && $parcelas > 0) {
        $valor_parcela = $valor / $parcelas;
        
        // Registrar empréstimo principal
        $divida_id = registrarDivida($conn, $funcionario_id, $valor, $descricao, 'emprestimo', $data_primeira_parcela);
        
        if ($divida_id) {
            $sucesso = true;
            
            // Criar parcelas
            for ($i = 1; $i <= $parcelas; $i++) {
                $data_parcela = date('Y-m-d', strtotime("+".($i-1)." months", strtotime($data_primeira_parcela)));
                $mes_parcela = (int)date('m', strtotime($data_parcela));
                $ano_parcela = (int)date('Y', strtotime($data_parcela));
                
                // Registrar parcela
                $sql_parcela = "INSERT INTO parcelas_emprestimo 
                                (divida_id, numero_parcela, valor_parcela, data_vencimento, mes_competencia, ano_competencia, status)
                                VALUES 
                                (:divida_id, :numero, :valor, :data_vencimento, :mes, :ano, 'pendente')";
                
                $stmt_parcela = $conn->prepare($sql_parcela);
                $result_parcela = $stmt_parcela->execute([
                    ':divida_id' => $divida_id,
                    ':numero' => $i,
                    ':valor' => $valor_parcela,
                    ':data_vencimento' => $data_parcela,
                    ':mes' => $mes_parcela,
                    ':ano' => $ano_parcela
                ]);
                
                if (!$result_parcela) {
                    $sucesso = false;
                    break;
                }
                
                // Se for para descontar na folha e é o mês atual
                if ($desconto_folha && $mes_parcela == date('m') && $ano_parcela == date('Y')) {
                    processarDescontoAutomaticoFolha($conn, $funcionario_id, $mes_parcela, $ano_parcela, $valor_parcela, "Parcela $i/$parcelas - $descricao");
                }
            }
            
            if ($sucesso) {
                $mensagem = "Empréstimo registrado com sucesso! " . ($desconto_folha ? "O desconto será aplicado na folha deste mês." : "");
            } else {
                $erro = "Erro ao registrar as parcelas do empréstimo.";
            }
        } else {
            $erro = "Erro ao registrar empréstimo.";
        }
    } else {
        $erro = "Valor inválido ou número de parcelas inválido.";
    }
}

// Processar pagamento de parcela
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'pagar_parcela') {
    $parcela_id = (int)$_POST['parcela_id'];
    $forma_pagamento = $_POST['forma_pagamento'];
    $observacao = $_POST['observacao'] ?? '';
    
    if ($parcela_id > 0) {
        if (marcarParcelaPaga($conn, $parcela_id, $forma_pagamento, $observacao)) {
            $mensagem = "Parcela paga com sucesso!";
        } else {
            $erro = "Erro ao processar pagamento da parcela.";
        }
    } else {
        $erro = "Parcela inválida.";
    }
}

// ============================================
// BUSCAR DADOS PARA EXIBIÇÃO
// ============================================

// Buscar dívidas ativas com suas parcelas
$sql_dividas = "SELECT d.*, 
                (SELECT COUNT(*) FROM parcelas_emprestimo WHERE divida_id = d.id AND status = 'pendente') as parcelas_pendentes,
                (SELECT COUNT(*) FROM parcelas_emprestimo WHERE divida_id = d.id AND status = 'pago') as parcelas_pagas
                FROM dividas_funcionarios d
                WHERE d.funcionario_id = :funcionario_id 
                AND d.status IN ('pendente', 'parcial')
                ORDER BY d.data_vencimento ASC, d.created_at DESC";

$stmt_dividas = $conn->prepare($sql_dividas);
$stmt_dividas->execute([':funcionario_id' => $funcionario_id]);
$dividas = $stmt_dividas->fetchAll(PDO::FETCH_ASSOC);

// Buscar parcelas pendentes
$sql_parcelas = "SELECT p.*, d.descricao as emprestimo_descricao, d.valor as valor_total
                 FROM parcelas_emprestimo p
                 JOIN dividas_funcionarios d ON d.id = p.divida_id
                 WHERE d.funcionario_id = :funcionario_id AND p.status = 'pendente'
                 ORDER BY p.data_vencimento ASC";

$stmt_parcelas = $conn->prepare($sql_parcelas);
$stmt_parcelas->execute([':funcionario_id' => $funcionario_id]);
$parcelas_pendentes = $stmt_parcelas->fetchAll(PDO::FETCH_ASSOC);

// Buscar histórico de pagamentos
$sql_pagamentos = "SELECT p.*, d.descricao, d.valor as valor_total,
                   CASE 
                       WHEN p.parcela_id IS NOT NULL THEN CONCAT('Parcela ', pc.numero_parcela)
                       ELSE 'Pagamento Direto'
                   END as detalhe
                   FROM pagamentos_dividas p
                   JOIN dividas_funcionarios d ON d.id = p.divida_id
                   LEFT JOIN parcelas_emprestimo pc ON pc.id = p.parcela_id
                   WHERE d.funcionario_id = :funcionario_id
                   ORDER BY p.data_pagamento DESC
                   LIMIT 50";

$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute([':funcionario_id' => $funcionario_id]);
$pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// Buscar histórico de descontos em folha
$sql_descontos = "SELECT fpf.*, u.nome as processado_por_nome
                  FROM folha_processamento_funcionarios fpf
                  LEFT JOIN usuarios u ON u.id = fpf.processado_por
                  WHERE fpf.funcionario_id = :funcionario_id 
                  AND (fpf.outros_descontos > 0 OR fpf.desconto_emprestimo > 0)
                  ORDER BY fpf.ano_competencia DESC, fpf.mes_competencia DESC
                  LIMIT 20";

$stmt_descontos = $conn->prepare($sql_descontos);
$stmt_descontos->execute([':funcionario_id' => $funcionario_id]);
$descontos_folha = $stmt_descontos->fetchAll(PDO::FETCH_ASSOC);

// Calcular totais
$total_divida = 0;
foreach ($dividas as $divida) {
    $total_divida += ($divida['valor'] - $divida['valor_pago']);
}

$total_parcelas_pendentes = array_sum(array_column($parcelas_pendentes, 'valor_parcela'));
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Dívidas e Empréstimos | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }
        
        /* Page Header */
        .page-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 24px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '💰';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 120px;
            opacity: 0.1;
        }
        
        /* Cards */
        .stats-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.12);
        }
        
        .stats-card.danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .stats-card.warning {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: #212529;
        }
        
        .stats-card.success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
        }
        
        .stats-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
        }
        
        /* Cards de conteúdo */
        .content-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px 20px;
            border-bottom: 2px solid var(--primary-green);
            font-weight: 700;
            color: var(--primary-green);
        }
        
        .card-header-custom i {
            margin-right: 10px;
        }
        
        /* Tabelas */
        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-custom th {
            background: #f8f9fa;
            padding: 12px;
            font-weight: 600;
            font-size: 0.8rem;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        .table-custom td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .table-custom tr:hover {
            background: #f8f9fa;
        }
        
        /* Badges */
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-pendente { background: #ffc107; color: #212529; }
        .badge-parcial { background: #17a2b8; color: white; }
        .badge-pago { background: #28a745; color: white; }
        .badge-cancelado { background: #dc3545; color: white; }
        
        /* Botões */
        .btn-primary-custom {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
            color: white;
        }
        
        .btn-outline-custom {
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            border-radius: 50px;
            padding: 5px 15px;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }
        
        .btn-outline-custom:hover {
            background: var(--primary-green);
            color: white;
        }
        
        /* Modal */
        .modal-header-custom {
            background: var(--primary-gradient);
            color: white;
        }
        
        /* Valor em destaque */
        .valor-destaque {
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .valor-positivo { color: #28a745; }
        .valor-negativo { color: #dc3545; }
        
        /* Animações */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in {
            animation: fadeInUp 0.5s ease-out;
        }
        
        /* Responsividade */
        @media (max-width: 768px) {
            .table-custom {
                font-size: 0.75rem;
            }
            
            .table-custom th,
            .table-custom td {
                padding: 8px;
            }
            
            .stats-number {
                font-size: 1.5rem;
            }
        }
        
        /* Impressão */
        @media print {
            .no-print, .btn, .modal, .page-header .no-print {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .stats-card {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-hand-holding-usd me-2"></i> Dívidas e Empréstimos</h2>
                    <p>Gerencie seus empréstimos e acompanhe os descontos em folha</p>
                </div>
                <div class="no-print">
                    <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalNovoEmprestimo">
                        <i class="fas fa-plus me-2"></i> Solicitar Empréstimo
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mensagens -->
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show fade-in" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($mensagem); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show fade-in" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($erro); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card danger fade-in">
                    <div class="stats-number">KZ <?php echo number_format($total_divida, 2, ',', '.'); ?></div>
                    <div class="stats-label">Total em Dívidas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card warning fade-in">
                    <div class="stats-number"><?php echo count($dividas); ?></div>
                    <div class="stats-label">Empréstimos Ativos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card fade-in">
                    <div class="stats-number"><?php echo count($parcelas_pendentes); ?></div>
                    <div class="stats-label">Parcelas Pendentes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card success fade-in">
                    <div class="stats-number">KZ <?php echo number_format($total_parcelas_pendentes, 2, ',', '.'); ?></div>
                    <div class="stats-label">Valor a Pagar</div>
                </div>
            </div>
        </div>
        
        <!-- Parcelas Pendentes -->
        <div class="content-card fade-in">
            <div class="card-header-custom">
                <i class="fas fa-calendar-alt"></i> Parcelas Pendentes
                <span class="badge bg-warning text-dark ms-2"><?php echo count($parcelas_pendentes); ?> pendente(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Empréstimo</th>
                            <th>Valor da Parcela</th>
                            <th>Vencimento</th>
                            <th>Competência</th>
                            <th class="text-center">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($parcelas_pendentes)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                                    Nenhuma parcela pendente. Todas as parcelas estão em dia!
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($parcelas_pendentes as $index => $parcela): ?>
                            <tr>
                                <td><?php echo $parcela['numero_parcela']; ?>ª</td>
                                <td><?php echo htmlspecialchars(substr($parcela['emprestimo_descricao'], 0, 30)); ?></td>
                                <td class="valor-negativo fw-bold">KZ <?php echo number_format($parcela['valor_parcela'], 2, ',', '.'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($parcela['data_vencimento'])); ?></td>
                                <td><?php echo $parcela['mes_competencia'] . '/' . $parcela['ano_competencia']; ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-custom" onclick="pagarParcela(<?php echo $parcela['id']; ?>, <?php echo $parcela['valor_parcela']; ?>)">
                                        <i class="fas fa-check"></i> Pagar
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Empréstimos Ativos -->
        <div class="content-card fade-in">
            <div class="card-header-custom">
                <i class="fas fa-hand-holding-usd"></i> Empréstimos Ativos
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Descrição</th>
                            <th>Valor Total</th>
                            <th>Valor Pago</th>
                            <th>Saldo Devedor</th>
                            <th>Parcelas</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dividas)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-coins fa-2x mb-2 d-block"></i>
                                    Nenhum empréstimo ativo no momento.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dividas as $divida): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($divida['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($divida['descricao']); ?></td>
                                <td>KZ <?php echo number_format($divida['valor'], 2, ',', '.'); ?></td>
                                <td class="text-success">KZ <?php echo number_format($divida['valor_pago'], 2, ',', '.'); ?></td>
                                <td class="valor-negativo fw-bold">KZ <?php echo number_format($divida['valor'] - $divida['valor_pago'], 2, ',', '.'); ?></td>
                                <td>
                                    <span class="badge bg-info"><?php echo $divida['parcelas_pagas']; ?> paga(s)</span>
                                    <span class="badge bg-warning text-dark"><?php echo $divida['parcelas_pendentes']; ?> pendente(s)</span>
                                </td>
                                <td>
                                    <span class="badge-status badge-<?php echo $divida['status']; ?>">
                                        <?php echo ucfirst($divida['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Histórico de Descontos em Folha -->
        <?php if (!empty($descontos_folha)): ?>
        <div class="content-card fade-in">
            <div class="card-header-custom">
                <i class="fas fa-file-invoice-dollar"></i> Histórico de Descontos em Folha
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Competência</th>
                            <th>Desconto Empréstimo</th>
                            <th>Outros Descontos</th>
                            <th>Total Descontos</th>
                            <th>Salário Líquido</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($descontos_folha as $desconto): ?>
                        <tr>
                            <td><?php echo getMesExtenso($desconto['mes_competencia']) . '/' . $desconto['ano_competencia']; ?></td>
                            <td class="valor-negativo">KZ <?php echo number_format($desconto['desconto_emprestimo'] ?? 0, 2, ',', '.'); ?></td>
                            <td class="valor-negativo">KZ <?php echo number_format($desconto['outros_descontos'] ?? 0, 2, ',', '.'); ?></td>
                            <td class="valor-negativo fw-bold">KZ <?php echo number_format($desconto['total_descontos'], 2, ',', '.'); ?></td>
                            <td class="text-success fw-bold">KZ <?php echo number_format($desconto['salario_liquido'], 2, ',', '.'); ?></td>
                            <td>
                                <span class="badge-status badge-<?php echo $desconto['status']; ?>">
                                    <?php echo ucfirst($desconto['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Histórico de Pagamentos -->
        <?php if (!empty($pagamentos)): ?>
        <div class="content-card fade-in">
            <div class="card-header-custom">
                <i class="fas fa-history"></i> Histórico de Pagamentos
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Referente a</th>
                            <th>Detalhe</th>
                            <th>Valor Pago</th>
                            <th>Forma de Pagamento</th>
                            <th>Observação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagamentos as $pagamento): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($pagamento['data_pagamento'])); ?></td>
                            <td><?php echo htmlspecialchars(substr($pagamento['descricao'], 0, 30)); ?></td>
                            <td><?php echo $pagamento['detalhe']; ?></td>
                            <td class="text-success fw-bold">KZ <?php echo number_format($pagamento['valor_pago'], 2, ',', '.'); ?></td>
                            <td><?php echo ucfirst($pagamento['forma_pagamento']); ?></td>
                            <td><?php echo htmlspecialchars($pagamento['observacao']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Novo Empréstimo -->
    <div class="modal fade" id="modalNovoEmprestimo" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title">
                        <i class="fas fa-hand-holding-usd me-2"></i> Solicitar Empréstimo
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="novo_emprestimo">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Valor do Empréstimo (KZ)</label>
                            <input type="number" name="valor" class="form-control" required min="1000" step="1000" placeholder="Ex: 100000">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Número de Parcelas</label>
                            <select name="parcelas" class="form-select" required>
                                <option value="1">1x (Uma parcela)</option>
                                <option value="2">2x (Duas parcelas)</option>
                                <option value="3">3x (Três parcelas)</option>
                                <option value="4">4x (Quatro parcelas)</option>
                                <option value="5">5x (Cinco parcelas)</option>
                                <option value="6">6x (Seis parcelas)</option>
                                <option value="8">8x (Oito parcelas)</option>
                                <option value="10">10x (Dez parcelas)</option>
                                <option value="12" selected>12x (Doze parcelas)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Data da Primeira Parcela</label>
                            <input type="date" name="data_primeira_parcela" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                            <small class="text-muted">A primeira parcela vencerá nesta data.</small>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="desconto_folha" class="form-check-input" id="descontoFolha" checked>
                                <label class="form-check-label" for="descontoFolha">
                                    <i class="fas fa-file-invoice-dollar"></i> Descontar automaticamente da folha de pagamento
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descrição (opcional)</label>
                            <textarea name="descricao" class="form-control" rows="2" placeholder="Motivo do empréstimo..."></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Informações importantes:</strong>
                            <ul class="mb-0 mt-2">
                                <li>O valor das parcelas será descontado mensalmente da sua folha de pagamento.</li>
                                <li>Em caso de atraso, serão aplicadas multas conforme política interna.</li>
                                <li>Para mais informações, contacte o departamento financeiro.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary-custom">Solicitar Empréstimo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Pagar Parcela -->
    <div class="modal fade" id="modalPagarParcela" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i> Pagar Parcela
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="pagar_parcela">
                    <input type="hidden" name="parcela_id" id="parcela_id">
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <div class="display-6 text-success" id="valor_parcela_display"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Forma de Pagamento</label>
                            <select name="forma_pagamento" class="form-select" required>
                                <option value="dinheiro">Dinheiro</option>
                                <option value="transferencia">Transferência Bancária</option>
                                <option value="cheque">Cheque</option>
                                <option value="desconto_folha">Desconto em Folha</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Observação (opcional)</label>
                            <textarea name="observacao" class="form-control" rows="2" placeholder="Comprovante, referência, etc..."></textarea>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Após confirmar, esta parcela será marcada como paga e registrada no sistema.
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function pagarParcela(parcelaId, valor) {
            document.getElementById('parcela_id').value = parcelaId;
            document.getElementById('valor_parcela_display').innerHTML = 'KZ ' + formatMoney(valor);
            
            var modal = new bootstrap.Modal(document.getElementById('modalPagarParcela'));
            modal.show();
        }
        
        function formatMoney(value) {
            return value.toLocaleString('pt-AO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        // Animações ao scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fade-in');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.content-card, .stats-card').forEach(el => {
                observer.observe(el);
            });
        });
    </script>
</body>
</html>

<?php
// Função auxiliar para nome do mês
function getMesExtenso($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[(int)$mes];
}
?>