<?php
// escola/financeiro/folha_pagamento/processar.php - Processamento de Folha Completo
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

// Desabilitar exibição de erros para garantir JSON puro
error_reporting(0);
ini_set('display_errors', 0);

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function calcularINSS($salario) {
    if ($salario <= 100000) return $salario * 0.03;
    if ($salario <= 200000) return $salario * 0.06 - 3000;
    if ($salario <= 350000) return $salario * 0.09 - 9000;
    return $salario * 0.12 - 19500;
}

function calcularIRRF($salario) {
    if ($salario <= 100000) return 0;
    if ($salario <= 200000) return $salario * 0.10 - 10000;
    if ($salario <= 350000) return $salario * 0.15 - 20000;
    if ($salario <= 500000) return $salario * 0.20 - 37500;
    return $salario * 0.25 - 62500;
}

// ============================================
// PROCESSAR AÇÕES AJAX
// ============================================

// Verificar se é uma requisição AJAX
$is_ajax = isset($_GET['ajax']) || isset($_POST['ajax']);

// Buscar funcionários disponíveis para adicionar
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_funcionarios_disponiveis') {
    header('Content-Type: application/json');
    try {
        $processamento_id = $_GET['processamento_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT 
                f.id, 
                f.numero_processo, 
                f.nome, 
                f.cargo,
                COALESCE(f.salario_base, 0) as salario_base,
                COALESCE(f.subsidio_transporte, 0) as subsidio_transporte,
                COALESCE(f.subsidio_alimentacao, 0) as subsidio_alimentacao,
                COALESCE(f.outros_vencimentos, 0) as outros_vencimentos
            FROM funcionarios f
            WHERE f.escola_id = ? AND f.status = 'ativo'
            AND NOT EXISTS (
                SELECT 1 FROM folha_processamento_funcionarios pf 
                WHERE pf.funcionario_id = f.id AND pf.processamento_id = ?
            )
            ORDER BY f.nome
        ");
        $stmt->execute([$escola_id, $processamento_id]);
        $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'funcionarios' => $funcionarios]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'funcionarios' => []]);
    }
    exit;
}

// Adicionar múltiplos funcionários em massa
if (isset($_POST['ajax']) && $_POST['ajax'] == 'add_multiplos_funcionarios') {
    header('Content-Type: application/json');
    try {
        $processamento_id = $_POST['processamento_id'] ?? 0;
        $funcionarios_ids = json_decode($_POST['funcionarios_ids'], true);
        
        if (empty($funcionarios_ids)) {
            echo json_encode(['success' => false, 'message' => 'Nenhum funcionário selecionado!']);
            exit;
        }
        
        $adicionados = 0;
        $erros = [];
        
        foreach ($funcionarios_ids as $funcionario_id) {
            $stmt = $conn->prepare("
                SELECT 
                    id, numero_processo, nome, cargo,
                    COALESCE(salario_base, 0) as salario_base,
                    COALESCE(subsidio_transporte, 0) as subsidio_transporte,
                    COALESCE(subsidio_alimentacao, 0) as subsidio_alimentacao,
                    COALESCE(outros_vencimentos, 0) as outros_vencimentos
                FROM funcionarios
                WHERE id = ? AND escola_id = ?
            ");
            $stmt->execute([$funcionario_id, $escola_id]);
            $funcionario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$funcionario) {
                $erros[] = "Funcionário ID {$funcionario_id} não encontrado!";
                continue;
            }
            
            $salario_base = $funcionario['salario_base'];
            $subsidio_transporte = $funcionario['subsidio_transporte'];
            $subsidio_alimentacao = $funcionario['subsidio_alimentacao'];
            $outros_vencimentos = $funcionario['outros_vencimentos'];
            
            $total_vencimentos = $salario_base + $subsidio_transporte + $subsidio_alimentacao + $outros_vencimentos;
            $inss = calcularINSS($salario_base);
            $base_irrf = $total_vencimentos - $inss;
            $irrf = calcularIRRF($base_irrf);
            $total_descontos = $inss + $irrf;
            $salario_liquido = $total_vencimentos - $total_descontos;
            
            $stmt = $conn->prepare("SELECT id FROM folha_processamento_funcionarios WHERE processamento_id = ? AND funcionario_id = ?");
            $stmt->execute([$processamento_id, $funcionario_id]);
            
            if ($stmt->fetch()) {
                $erros[] = "Funcionário {$funcionario['nome']} já está no processamento!";
                continue;
            }
            
            $stmt = $conn->prepare("
                INSERT INTO folha_processamento_funcionarios (
                    processamento_id, funcionario_id, salario_base, subsidio_transporte, 
                    subsidio_alimentacao, outros_vencimentos, total_vencimentos, 
                    valor_inss, valor_irrf, total_descontos, salario_liquido
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $processamento_id, $funcionario_id,
                $salario_base, $subsidio_transporte, 
                $subsidio_alimentacao, $outros_vencimentos,
                $total_vencimentos, $inss, $irrf, $total_descontos, $salario_liquido
            ]);
            
            $adicionados++;
        }
        
        $mensagem = "{$adicionados} funcionário(s) adicionado(s) com sucesso!";
        if (!empty($erros)) {
            $mensagem .= " Erros: " . implode(", ", $erros);
        }
        
        echo json_encode(['success' => true, 'message' => $mensagem, 'adicionados' => $adicionados]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

// Buscar funcionários no processamento
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_funcionarios_processamento') {
    header('Content-Type: application/json');
    try {
        $processamento_id = $_GET['processamento_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT 
                pf.*,
                f.numero_processo,
                f.nome,
                f.cargo,
                f.iban
            FROM folha_processamento_funcionarios pf
            JOIN funcionarios f ON pf.funcionario_id = f.id
            WHERE pf.processamento_id = ?
            ORDER BY f.nome
        ");
        $stmt->execute([$processamento_id]);
        $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'funcionarios' => $funcionarios]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'funcionarios' => []]);
    }
    exit;
}

// Buscar resumo dos totais do processamento
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_resumo_totais') {
    header('Content-Type: application/json');
    try {
        $processamento_id = $_GET['processamento_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_funcionarios,
                COALESCE(SUM(salario_base), 0) as total_salario_base,
                COALESCE(SUM(subsidio_transporte + subsidio_alimentacao + outros_vencimentos), 0) as total_subsidios,
                COALESCE(SUM(faltas_valor), 0) as total_faltas,
                COALESCE(SUM(valor_inss), 0) as total_inss,
                COALESCE(SUM(valor_irrf), 0) as total_irrf,
                COALESCE(SUM(total_descontos), 0) as total_descontos,
                COALESCE(SUM(salario_liquido), 0) as total_liquido
            FROM folha_processamento_funcionarios
            WHERE processamento_id = ?
        ");
        $stmt->execute([$processamento_id]);
        $totais = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'totais' => $totais]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Adicionar funcionário individual
if (isset($_POST['ajax']) && $_POST['ajax'] == 'add_funcionario') {
    header('Content-Type: application/json');
    try {
        $processamento_id = $_POST['processamento_id'] ?? 0;
        $funcionario_id = $_POST['funcionario_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT 
                id, numero_processo, nome, cargo,
                COALESCE(salario_base, 0) as salario_base,
                COALESCE(subsidio_transporte, 0) as subsidio_transporte,
                COALESCE(subsidio_alimentacao, 0) as subsidio_alimentacao,
                COALESCE(outros_vencimentos, 0) as outros_vencimentos
            FROM funcionarios
            WHERE id = ? AND escola_id = ?
        ");
        $stmt->execute([$funcionario_id, $escola_id]);
        $funcionario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$funcionario) {
            echo json_encode(['success' => false, 'message' => 'Funcionário não encontrado!']);
            exit;
        }
        
        $salario_base = $funcionario['salario_base'];
        $subsidio_transporte = $funcionario['subsidio_transporte'];
        $subsidio_alimentacao = $funcionario['subsidio_alimentacao'];
        $outros_vencimentos = $funcionario['outros_vencimentos'];
        
        $total_vencimentos = $salario_base + $subsidio_transporte + $subsidio_alimentacao + $outros_vencimentos;
        $inss = calcularINSS($salario_base);
        $base_irrf = $total_vencimentos - $inss;
        $irrf = calcularIRRF($base_irrf);
        $total_descontos = $inss + $irrf;
        $salario_liquido = $total_vencimentos - $total_descontos;
        
        $stmt = $conn->prepare("SELECT id FROM folha_processamento_funcionarios WHERE processamento_id = ? AND funcionario_id = ?");
        $stmt->execute([$processamento_id, $funcionario_id]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Funcionário já está no processamento!']);
            exit;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO folha_processamento_funcionarios (
                processamento_id, funcionario_id, salario_base, subsidio_transporte, 
                subsidio_alimentacao, outros_vencimentos, total_vencimentos, 
                valor_inss, valor_irrf, total_descontos, salario_liquido
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $processamento_id, $funcionario_id,
            $salario_base, $subsidio_transporte, 
            $subsidio_alimentacao, $outros_vencimentos,
            $total_vencimentos, $inss, $irrf, $total_descontos, $salario_liquido
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Funcionário adicionado com sucesso!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

// Remover funcionário do processamento
if (isset($_POST['ajax']) && $_POST['ajax'] == 'remove_funcionario') {
    header('Content-Type: application/json');
    try {
        $processamento_id = $_POST['processamento_id'] ?? 0;
        $funcionario_id = $_POST['funcionario_id'] ?? 0;
        
        $stmt = $conn->prepare("DELETE FROM folha_processamento_funcionarios WHERE processamento_id = ? AND funcionario_id = ?");
        $stmt->execute([$processamento_id, $funcionario_id]);
        
        $stmt = $conn->prepare("DELETE FROM folha_faltas WHERE funcionario_id = ?");
        $stmt->execute([$funcionario_id]);
        
        echo json_encode(['success' => true, 'message' => 'Funcionário removido!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Atualizar configuração salarial do funcionário
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_config') {
    header('Content-Type: application/json');
    try {
        $funcionario_id = $_POST['funcionario_id'] ?? 0;
        $salario_base = $_POST['salario_base'] ?? 0;
        $subsidio_transporte = $_POST['subsidio_transporte'] ?? 0;
        $subsidio_alimentacao = $_POST['subsidio_alimentacao'] ?? 0;
        $outros_vencimentos = $_POST['outros_vencimentos'] ?? 0;
        
        $stmt = $conn->prepare("
            UPDATE funcionarios SET 
                salario_base = ?,
                subsidio_transporte = ?,
                subsidio_alimentacao = ?,
                outros_vencimentos = ?,
                updated_at = NOW()
            WHERE id = ? AND escola_id = ?
        ");
        $stmt->execute([$salario_base, $subsidio_transporte, $subsidio_alimentacao, $outros_vencimentos, $funcionario_id, $escola_id]);
        
        // Atualizar também no processamento atual
        $stmt = $conn->prepare("
            UPDATE folha_processamento_funcionarios SET 
                salario_base = ?,
                subsidio_transporte = ?,
                subsidio_alimentacao = ?,
                outros_vencimentos = ?,
                total_vencimentos = ? + ? + ? + ?,
                valor_inss = ?,
                valor_irrf = ?,
                total_descontos = ? + ?,
                salario_liquido = (? + ? + ? + ?) - (? + ?)
            WHERE funcionario_id = ?
        ");
        
        $total_vencimentos = $salario_base + $subsidio_transporte + $subsidio_alimentacao + $outros_vencimentos;
        $inss = calcularINSS($salario_base);
        $base_irrf = $total_vencimentos - $inss;
        $irrf = calcularIRRF($base_irrf);
        
        $stmt->execute([
            $salario_base, $subsidio_transporte, $subsidio_alimentacao, $outros_vencimentos,
            $salario_base, $subsidio_transporte, $subsidio_alimentacao, $outros_vencimentos,
            $inss, $irrf, $inss, $irrf,
            $salario_base, $subsidio_transporte, $subsidio_alimentacao, $outros_vencimentos, $inss, $irrf,
            $funcionario_id
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Configuração atualizada!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Buscar faltas de um funcionário
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_faltas') {
    header('Content-Type: application/json');
    try {
        $funcionario_id = $_GET['funcionario_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT * FROM folha_faltas 
            WHERE funcionario_id = ?
            ORDER BY data_falta DESC
        ");
        $stmt->execute([$funcionario_id]);
        $faltas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'faltas' => $faltas]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'faltas' => []]);
    }
    exit;
}

// Adicionar falta
if (isset($_POST['ajax']) && $_POST['ajax'] == 'add_falta') {
    header('Content-Type: application/json');
    try {
        $funcionario_id = $_POST['funcionario_id'] ?? 0;
        $data_falta = $_POST['data_falta'] ?? date('Y-m-d');
        $quantidade_dias = $_POST['quantidade_dias'] ?? 1;
        $tipo_falta = $_POST['tipo_falta'] ?? 'injustificada';
        $valor_desconto_dia = $_POST['valor_desconto_dia'] ?? 0;
        $percentual_desconto = $_POST['percentual_desconto'] ?? 100;
        $justificativa = $_POST['justificativa'] ?? '';
        
        $stmt = $conn->prepare("
            SELECT salario_base FROM folha_processamento_funcionarios WHERE funcionario_id = ?
        ");
        $stmt->execute([$funcionario_id]);
        $func = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $salario_dia = ($func['salario_base'] ?? 0) / 22;
        
        if ($valor_desconto_dia > 0) {
            $valor_desconto = $valor_desconto_dia * $quantidade_dias;
        } else {
            $valor_desconto = ($salario_dia * $percentual_desconto / 100) * $quantidade_dias;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO folha_faltas (
                escola_id, funcionario_id, data_falta, quantidade_dias, 
                tipo_falta, valor_desconto_dia, percentual_desconto, 
                valor_desconto, justificativa
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $escola_id, $funcionario_id, $data_falta, $quantidade_dias,
            $tipo_falta, $valor_desconto_dia, $percentual_desconto,
            $valor_desconto, $justificativa
        ]);
        
        $stmt = $conn->prepare("
            UPDATE folha_processamento_funcionarios SET 
                faltas_dias = (SELECT COALESCE(SUM(quantidade_dias), 0) FROM folha_faltas WHERE funcionario_id = ?),
                faltas_valor = (SELECT COALESCE(SUM(valor_desconto), 0) FROM folha_faltas WHERE funcionario_id = ?),
                total_vencimentos = salario_base + subsidio_transporte + subsidio_alimentacao + outros_vencimentos - faltas_valor,
                salario_liquido = total_vencimentos - (valor_inss + valor_irrf)
            WHERE funcionario_id = ?
        ");
        $stmt->execute([$funcionario_id, $funcionario_id, $funcionario_id]);
        
        echo json_encode(['success' => true, 'message' => 'Falta registada!', 'valor_desconto' => $valor_desconto]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Remover falta
if (isset($_POST['ajax']) && $_POST['ajax'] == 'remove_falta') {
    header('Content-Type: application/json');
    try {
        $falta_id = $_POST['falta_id'] ?? 0;
        $funcionario_id = $_POST['funcionario_id'] ?? 0;
        
        $stmt = $conn->prepare("DELETE FROM folha_faltas WHERE id = ?");
        $stmt->execute([$falta_id]);
        
        $stmt = $conn->prepare("
            UPDATE folha_processamento_funcionarios SET 
                faltas_dias = (SELECT COALESCE(SUM(quantidade_dias), 0) FROM folha_faltas WHERE funcionario_id = ?),
                faltas_valor = (SELECT COALESCE(SUM(valor_desconto), 0) FROM folha_faltas WHERE funcionario_id = ?),
                total_vencimentos = salario_base + subsidio_transporte + subsidio_alimentacao + outros_vencimentos - faltas_valor,
                salario_liquido = total_vencimentos - (valor_inss + valor_irrf)
            WHERE funcionario_id = ?
        ");
        $stmt->execute([$funcionario_id, $funcionario_id, $funcionario_id]);
        
        echo json_encode(['success' => true, 'message' => 'Falta removida!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Calcular folha
if (isset($_POST['ajax']) && $_POST['ajax'] == 'calcular') {
    header('Content-Type: application/json');
    try {
        $processamento_id = $_POST['processamento_id'] ?? 0;
        
        $stmt = $conn->prepare("
            SELECT * FROM folha_processamento_funcionarios
            WHERE processamento_id = ?
        ");
        $stmt->execute([$processamento_id]);
        $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($funcionarios as $func) {
            $stmtFaltas = $conn->prepare("
                SELECT COALESCE(SUM(quantidade_dias), 0) as total_dias, COALESCE(SUM(valor_desconto), 0) as total_valor
                FROM folha_faltas
                WHERE funcionario_id = ?
            ");
            $stmtFaltas->execute([$func['funcionario_id']]);
            $faltas = $stmtFaltas->fetch(PDO::FETCH_ASSOC);
            
            $faltas_dias = $faltas['total_dias'];
            $faltas_valor = $faltas['total_valor'];
            
            $salario_base = $func['salario_base'];
            $subsidio_transporte = $func['subsidio_transporte'];
            $subsidio_alimentacao = $func['subsidio_alimentacao'];
            $outros_vencimentos = $func['outros_vencimentos'];
            
            $total_vencimentos = $salario_base + $subsidio_transporte + $subsidio_alimentacao + $outros_vencimentos - $faltas_valor;
            $inss = calcularINSS($salario_base);
            $base_irrf = $total_vencimentos - $inss;
            $irrf = calcularIRRF($base_irrf);
            $total_descontos = $inss + $irrf + $faltas_valor;
            $salario_liquido = $total_vencimentos - $total_descontos;
            
            $stmt = $conn->prepare("
                UPDATE folha_processamento_funcionarios SET 
                    faltas_dias = ?,
                    faltas_valor = ?,
                    total_vencimentos = ?,
                    valor_inss = ?,
                    valor_irrf = ?,
                    total_descontos = ?,
                    salario_liquido = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $faltas_dias, $faltas_valor,
                $total_vencimentos, $inss, $irrf, $total_descontos, $salario_liquido,
                $func['id']
            ]);
        }
        
        $stmt = $conn->prepare("
            UPDATE folha_processamento_cabecalho SET 
                status = 'processado',
                total_funcionarios = (SELECT COUNT(*) FROM folha_processamento_funcionarios WHERE processamento_id = ?),
                total_vencimentos = (SELECT SUM(total_vencimentos) FROM folha_processamento_funcionarios WHERE processamento_id = ?),
                total_descontos = (SELECT SUM(total_descontos) FROM folha_processamento_funcionarios WHERE processamento_id = ?),
                total_liquido = (SELECT SUM(salario_liquido) FROM folha_processamento_funcionarios WHERE processamento_id = ?)
            WHERE id = ?
        ");
        $stmt->execute([
            $processamento_id, $processamento_id, $processamento_id, $processamento_id,
            $processamento_id
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Folha calculada com sucesso!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fechar processamento
if (isset($_POST['ajax']) && $_POST['ajax'] == 'fechar') {
    header('Content-Type: application/json');
    try {
        $processamento_id = $_POST['processamento_id'] ?? 0;
        
        $stmt = $conn->prepare("UPDATE folha_processamento_cabecalho SET status = 'fechado' WHERE id = ?");
        $stmt->execute([$processamento_id]);
        
        echo json_encode(['success' => true, 'message' => 'Processamento fechado!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Buscar funcionário para pré-visualização de cálculo
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_funcionario_salario') {
    header('Content-Type: application/json');
    try {
        $funcionario_id = $_GET['funcionario_id'] ?? 0;
        
        $stmt = $conn->prepare("SELECT salario_base FROM funcionarios WHERE id = ?");
        $stmt->execute([$funcionario_id]);
        $func = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'salario_base' => $func['salario_base'] ?? 0]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Se não for AJAX, mostrar a página HTML
if (!$is_ajax) {

// ============================================
// PÁGINA PRINCIPAL
// ============================================

$ano_selecionado = $_GET['ano'] ?? date('Y');
$mes_selecionado = $_GET['mes'] ?? date('m');

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

// Buscar ou criar processamento atual
$stmt = $conn->prepare("SELECT * FROM folha_processamento_cabecalho WHERE escola_id = ? AND ano = ? AND mes = ?");
$stmt->execute([$escola_id, $ano_selecionado, $mes_selecionado]);
$processamento_atual = $stmt->fetch(PDO::FETCH_ASSOC);
$processamento_id = $processamento_atual['id'] ?? 0;

if (!$processamento_atual && $processamento_id == 0) {
    $stmt = $conn->prepare("INSERT INTO folha_processamento_cabecalho (escola_id, ano, mes, usuario_id, status) VALUES (?, ?, ?, ?, 'rascunho')");
    $stmt->execute([$escola_id, $ano_selecionado, $mes_selecionado, $usuario_id]);
    $processamento_id = $conn->lastInsertId();
}

// Buscar funcionários no processamento para exibir inicialmente
$stmt = $conn->prepare("
    SELECT 
        pf.*,
        f.numero_processo,
        f.nome,
        f.cargo,
        f.iban
    FROM folha_processamento_funcionarios pf
    JOIN funcionarios f ON pf.funcionario_id = f.id
    WHERE pf.processamento_id = ?
    ORDER BY f.nome
");
$stmt->execute([$processamento_id]);
$funcionarios_iniciais = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar totais iniciais
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_funcionarios,
        COALESCE(SUM(salario_base), 0) as total_salario_base,
        COALESCE(SUM(subsidio_transporte + subsidio_alimentacao + outros_vencimentos), 0) as total_subsidios,
        COALESCE(SUM(faltas_valor), 0) as total_faltas,
        COALESCE(SUM(valor_inss), 0) as total_inss,
        COALESCE(SUM(valor_irrf), 0) as total_irrf,
        COALESCE(SUM(total_descontos), 0) as total_descontos,
        COALESCE(SUM(salario_liquido), 0) as total_liquido
    FROM folha_processamento_funcionarios
    WHERE processamento_id = ?
");
$stmt->execute([$processamento_id]);
$totais_iniciais = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processamento de Folha | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-gerar-holerites { background: #17a2b8; color: white; }
        .btn-gerar-holerites:hover { background: #138496; }
        .btn-visualizar-folha { background: #6c757d; color: white; }
        .btn-visualizar-folha:hover { background: #5a6268; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; }
        .status-rascunho { background: #ffc107; color: #000; }
        .status-processado { background: #17a2b8; color: #fff; }
        .status-fechado { background: #28a745; color: #fff; }
        .table-funcionarios th { background: #e9ecef; }
        .salario-zero { color: #dc3545; font-style: italic; cursor: pointer; }
        .salario-zero:hover { text-decoration: underline; background-color: #fff3cd; }
        .funcionario-row-clickable { cursor: pointer; }
        .funcionario-row-clickable:hover { background-color: #f0f0f0; }
        .select-all-row { background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
        .funcionario-checkbox { cursor: pointer; }
        .card-totais { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 10px; padding: 15px; text-align: center; }
        .card-totais h3 { margin: 0; font-size: 1.5em; }
        .card-totais small { opacity: 0.8; }
        
        /* Estilos para notificações Toast */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            max-width: 400px;
            animation: slideInRight 0.3s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .toast-notification .toast {
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            border-radius: 8px;
            border: none;
        }
        
        .toast-notification .toast-header {
            border-radius: 8px 8px 0 0;
        }
        
        .toast-notification .toast-header.bg-success {
            background: linear-gradient(135deg, #28a745, #20c997);
        }
        
        .toast-notification .toast-header.bg-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }
        
        .toast-notification .toast-header.bg-warning {
            background: linear-gradient(135deg, #ffc107, #e0a800);
        }
        
        .toast-notification .toast-header.bg-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
        }
        
        .toast-notification .toast-body {
            font-size: 14px;
        }
        
        /* Modal de confirmação */
        .modal-confirm .modal-content {
            border-radius: 15px;
            border: none;
        }
        
        .modal-confirm .modal-header {
            border-radius: 15px 15px 0 0;
        }
        
        .modal-confirm .modal-body {
            padding: 20px;
        }
        
        .modal-confirm .icon-box {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }
        
        .modal-confirm .icon-box.warning {
            background: #fff3cd;
            color: #ffc107;
            border: 2px solid #ffc107;
        }
        
        .modal-confirm .icon-box.danger {
            background: #f8d7da;
            color: #dc3545;
            border: 2px solid #dc3545;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            display: none;
            justify-content: center;
            align-items: center;
        }
        
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #006B3E;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>
    
    <!-- Toast Container para notificações -->
    <div id="toastContainer" class="toast-notification"></div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade modal-confirm" id="modalConfirmacao" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" id="confirmHeader">
                    <h5 class="modal-title" id="confirmTitle">Confirmar Ação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="icon-box" id="confirmIcon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <p id="confirmMessage">Tem certeza que deseja realizar esta ação?</p>
                    <div id="confirmDetails" class="mt-2 text-start bg-light p-2 rounded" style="display: none;"></div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="confirmCancelBtn">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="confirmOkBtn">Confirmar</button>
                </div>
            </div>
        </div>
    </div>
    
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
            <li class="nav-item"><a href="funcionarios.php" class="nav-link"><i class="fas fa-users"></i> Funcionários</a></li>
            <li class="nav-item"><a href="processar.php" class="nav-link active"><i class="fas fa-calculator"></i> Processar</a></li>
            <li class="nav-item"><a href="holerites.php" class="nav-link"><i class="fas fa-receipt"></i> Holerites</a></li>
            <li class="nav-item"><a href="relatorios.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="configuracoes.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-calculator"></i> Processamento de Folha</h2>
            <div>
                <span class="badge bg-primary" id="periodoDisplay"><?php echo $meses[$mes_selecionado] . '/' . $ano_selecionado; ?></span>
                <span class="badge status-<?php echo $processamento_atual['status'] ?? 'rascunho'; ?> ms-2" id="statusBadge">
                    <?php echo ucfirst($processamento_atual['status'] ?? 'rascunho'); ?>
                </span>
            </div>
        </div>
        
        <!-- Seleção de Período -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-calendar-alt"></i> Selecionar Período
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <label>Ano</label>
                        <select id="selectAno" class="form-control">
                            <?php for ($i = date('Y')-2; $i <= date('Y')+1; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $i == $ano_selecionado ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label>Mês</label>
                        <select id="selectMes" class="form-control">
                            <?php foreach ($meses as $num => $nome): ?>
                                <option value="<?php echo $num; ?>" <?php echo $num == $mes_selecionado ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary form-control" onclick="carregarPeriodo()">
                            <i class="fas fa-search"></i> Carregar Período
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cards de Totais -->
        <div class="row mt-3">
            <div class="col-md-3">
                <div class="card-totais">
                    <i class="fas fa-users fa-2x"></i>
                    <h3 id="totalFuncionarios"><?php echo $totais_iniciais['total_funcionarios'] ?? 0; ?></h3>
                    <small>Funcionários</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-totais">
                    <i class="fas fa-chart-line fa-2x"></i>
                    <h3 id="totalVencimentos"><?php echo number_format(($totais_iniciais['total_salario_base'] ?? 0) + ($totais_iniciais['total_subsidios'] ?? 0), 2); ?> Kz</h3>
                    <small>Total Vencimentos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-totais">
                    <i class="fas fa-chart-line fa-2x"></i>
                    <h3 id="totalDescontos"><?php echo number_format(($totais_iniciais['total_faltas'] ?? 0) + ($totais_iniciais['total_inss'] ?? 0) + ($totais_iniciais['total_irrf'] ?? 0), 2); ?> Kz</h3>
                    <small>Total Descontos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-totais">
                    <i class="fas fa-money-bill fa-2x"></i>
                    <h3 id="totalLiquido"><?php echo number_format($totais_iniciais['total_liquido'] ?? 0, 2); ?> Kz</h3>
                    <small>Total Líquido</small>
                </div>
            </div>
        </div>
        
        <!-- Botões de Ação -->
        <div class="card mt-3">
            <div class="card-body">
                <div class="text-center">
                    <button class="btn btn-primary" onclick="abrirModalAdicionar()">
                        <i class="fas fa-plus"></i> Adicionar Funcionário
                    </button>
                    <button class="btn btn-success" onclick="calcularFolha()">
                        <i class="fas fa-calculator"></i> Calcular
                    </button>
                    <button class="btn btn-gerar-holerites" onclick="gerarHolerites()">
                        <i class="fas fa-file-pdf"></i> Gerar Holerites
                    </button>
                    <button class="btn btn-warning" onclick="fecharProcessamento()">
                        <i class="fas fa-lock"></i> Fechar
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Lista de Funcionários no Processamento -->
        <div class="card mt-3">
            <div class="card-header">
                <i class="fas fa-list"></i> Funcionários no Processamento
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-funcionarios" id="tabelaFuncionarios">
                        <thead>
                            <tr>
                                <th>Nº Processo</th>
                                <th>Nome</th>
                                <th>Cargo</th>
                                <th class="text-end">Salário Base</th>
                                <th class="text-end">Subsídios</th>
                                <th class="text-end">Faltas</th>
                                <th class="text-end">INSS</th>
                                <th class="text-end">IRRF</th>
                                <th class="text-end">Líquido</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyFuncionarios">
                            <?php if (count($funcionarios_iniciais) > 0): ?>
                                <?php foreach ($funcionarios_iniciais as $f): ?>
                                <tr id="row_<?php echo $f['funcionario_id']; ?>" class="funcionario-row-clickable" onclick="abrirModalConfigurarPorId(<?php echo $f['funcionario_id']; ?>, '<?php echo addslashes($f['nome']); ?>')">
                                    <td><?php echo htmlspecialchars($f['numero_processo']); ?></td>
                                    <td><?php echo htmlspecialchars($f['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($f['cargo']); ?></td>
                                    <td class="text-end <?php echo ($f['salario_base'] ?? 0) == 0 ? 'salario-zero' : ''; ?>" title="<?php echo ($f['salario_base'] ?? 0) == 0 ? 'Clique para configurar salário' : ''; ?>">
                                        <?php echo number_format($f['salario_base'] ?? 0, 2); ?> Kz
                                        <?php if (($f['salario_base'] ?? 0) == 0): ?>
                                            <i class="fas fa-exclamation-triangle text-warning"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?php echo number_format(($f['subsidio_transporte'] ?? 0) + ($f['subsidio_alimentacao'] ?? 0) + ($f['outros_vencimentos'] ?? 0), 2); ?> Kz</td>
                                    <td class="text-end text-danger"><?php echo number_format($f['faltas_valor'] ?? 0, 2); ?> Kz</td>
                                    <td class="text-end"><?php echo number_format($f['valor_inss'] ?? 0, 2); ?> Kz</td>
                                    <td class="text-end"><?php echo number_format($f['valor_irrf'] ?? 0, 2); ?> Kz</td>
                                    <td class="text-end"><strong><?php echo number_format($f['salario_liquido'] ?? 0, 2); ?> Kz</strong></td>
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <button class="btn btn-sm btn-warning" onclick="abrirModalFaltas(<?php echo $f['funcionario_id']; ?>, '<?php echo addslashes($f['nome']); ?>')" title="Faltas">
                                            <i class="fas fa-calendar-times"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="removerFuncionario(<?php echo $f['funcionario_id']; ?>)" title="Remover">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="10" class="text-center text-muted">Nenhum funcionário adicionado</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot id="tfootFuncionarios" style="<?php echo count($funcionarios_iniciais) > 0 ? '' : 'display: none;'; ?>">
                            <tr class="table-active">
                                <th colspan="3" class="text-end">TOTAIS:</th>
                                <th class="text-end" id="footTotalSalario"><?php echo number_format($totais_iniciais['total_salario_base'] ?? 0, 2); ?> Kz</th>
                                <th class="text-end" id="footTotalSubsidios"><?php echo number_format($totais_iniciais['total_subsidios'] ?? 0, 2); ?> Kz</th>
                                <th class="text-end" id="footTotalFaltas"><?php echo number_format($totais_iniciais['total_faltas'] ?? 0, 2); ?> Kz</th>
                                <th class="text-end" id="footTotalINSS"><?php echo number_format($totais_iniciais['total_inss'] ?? 0, 2); ?> Kz</th>
                                <th class="text-end" id="footTotalIRRF"><?php echo number_format($totais_iniciais['total_irrf'] ?? 0, 2); ?> Kz</th>
                                <th class="text-end" id="footTotalLiquido"><?php echo number_format($totais_iniciais['total_liquido'] ?? 0, 2); ?> Kz</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Botão Visualizar Folha de Pagamento Geral -->
                <div class="text-center mt-4">
                    <a href="visualizar_folha_pagamento.php?processamento_id=<?php echo $processamento_id; ?>" target="_blank" class="btn btn-visualizar-folha btn-lg">
                        <i class="fas fa-file-invoice-dollar"></i> Visualizar Folha de Pagamento Geral
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Adicionar Funcionário -->
    <div class="modal fade" id="modalAdicionar" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Adicionar Funcionários</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Selecione os funcionários que deseja adicionar ao processamento. Clique na linha para configurar o salário se estiver zero.
                    </div>
                    
                    <div class="mb-3">
                        <div class="input-group">
                            <input type="text" id="searchFuncionario" class="form-control" placeholder="Pesquisar funcionário por nome, processo ou cargo...">
                            <button class="btn btn-outline-secondary" onclick="filtrarFuncionarios()">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover table-sm" id="tabelaFuncionariosDisponiveis">
                            <thead class="table-light">
                                <tr class="select-all-row">
                                    <th width="40">
                                        <input type="checkbox" id="selectAllCheckbox" onchange="selecionarTodos()">
                                    </th>
                                    <th>Nº Processo</th>
                                    <th>Nome</th>
                                    <th>Cargo</th>
                                    <th class="text-end">Salário Base</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyFuncionariosDisponiveis">
                                <tr><td colspan="5" class="text-center">Carregando...</td></tr>
                            </tbody>
                            <tfoot id="tfootComparacao"></tfoot>
                        </table>
                    </div>
                    
                    <div class="alert alert-secondary mt-3">
                        <div class="row">
                            <div class="col-md-6">
                                <strong><i class="fas fa-check-square"></i> Selecionados:</strong> 
                                <span id="totalSelecionados">0</span> funcionário(s)
                            </div>
                            <div class="col-md-6 text-end">
                                <button class="btn btn-sm btn-outline-success" onclick="selecionarTodos()">
                                    <i class="fas fa-check-double"></i> Selecionar Todos
                                </button>
                                <button class="btn btn-sm btn-outline-danger ms-2" onclick="$('.funcionario-checkbox').prop('checked', false); atualizarTotalSelecionados();">
                                    <i class="fas fa-times"></i> Limpar Seleção
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="adicionarSelecionados()">
                        <i class="fas fa-user-plus"></i> Adicionar Selecionados
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Configurar Salário -->
    <div class="modal fade" id="modalConfigurar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-cog"></i> Configurar Salário</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="configFuncionarioId">
                    <div class="alert alert-info">
                        <strong>Funcionário:</strong> <span id="configFuncionarioNome"></span>
                    </div>
                    <div class="mb-3">
                        <label>Salário Base (Kz)</label>
                        <input type="number" id="configSalarioBase" class="form-control" step="0.01">
                    </div>
                    <div class="mb-3">
                        <label>Subsídio Transporte (Kz)</label>
                        <input type="number" id="configSubsidioTransporte" class="form-control" step="0.01">
                    </div>
                    <div class="mb-3">
                        <label>Subsídio Alimentação (Kz)</label>
                        <input type="number" id="configSubsidioAlimentacao" class="form-control" step="0.01">
                    </div>
                    <div class="mb-3">
                        <label>Outros Vencimentos (Kz)</label>
                        <input type="number" id="configOutrosVencimentos" class="form-control" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="salvarConfiguracao()">Salvar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Faltas -->
    <div class="modal fade" id="modalFaltas" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-times"></i> Gestão de Faltas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="faltasFuncionarioId">
                    <div class="alert alert-info">
                        <strong>Funcionário:</strong> <span id="faltasNomeFuncionario"></span>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <h6><i class="fas fa-plus-circle"></i> Nova Falta</h6>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <input type="date" id="novaFaltaData" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" id="novaFaltaDias" class="form-control" placeholder="Dias" step="0.5" min="0.5">
                                </div>
                                <div class="col-md-3">
                                    <select id="novaFaltaTipo" class="form-control">
                                        <option value="justificada">Justificada</option>
                                        <option value="injustificada" selected>Injustificada</option>
                                        <option value="atestado">Atestado Médico</option>
                                        <option value="licenca">Licença</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" id="novaFaltaValorDia" class="form-control" placeholder="Valor/Dia (Kz)" step="0.01">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" id="novaFaltaPercentual" class="form-control" placeholder="% Desconto" step="0.01" value="100">
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-10">
                                    <input type="text" id="novaFaltaJustificativa" class="form-control" placeholder="Justificativa (opcional)">
                                </div>
                                <div class="col-md-2">
                                    <button class="btn btn-success w-100" onclick="adicionarFalta()">
                                        <i class="fas fa-save"></i> Adicionar
                                    </button>
                                </div>
                            </div>
                            <div class="alert alert-secondary mt-2 small" id="calculoPreview" style="display: none;">
                                <i class="fas fa-calculator"></i> <strong>Pré-visualização do cálculo:</strong> 
                                <span id="previewCalculo"></span>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6><i class="fas fa-list"></i> Lista de Faltas</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Dias</th>
                                    <th>Tipo</th>
                                    <th>Valor Desconto</th>
                                    <th>Justificativa</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyFaltas">
                                <tr><td colspan="6" class="text-center">Carregando...</td></tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-active">
                                    <th colspan="3">TOTAL DE DESCONTOS POR FALTAS</th>
                                    <th id="totalFaltasValor">0 Kz</th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let processamentoId = <?php echo $processamento_id; ?>;
        let anoAtual = <?php echo $ano_selecionado; ?>;
        let mesAtual = <?php echo $mes_selecionado; ?>;
        let funcionariosDisponiveis = [];
        
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // ============================================
        // FUNÇÕES DE NOTIFICAÇÃO (TOAST)
        // ============================================
        
        let pendingAction = null;
        let pendingData = null;
        
        function showToast(message, type = 'success') {
            const toastContainer = document.getElementById('toastContainer');
            const toastId = 'toast_' + Date.now();
            
            let bgColor = '';
            let icon = '';
            
            switch(type) {
                case 'success':
                    bgColor = 'bg-success';
                    icon = 'fa-check-circle';
                    break;
                case 'danger':
                case 'error':
                    bgColor = 'bg-danger';
                    icon = 'fa-exclamation-circle';
                    break;
                case 'warning':
                    bgColor = 'bg-warning';
                    icon = 'fa-exclamation-triangle';
                    break;
                case 'info':
                    bgColor = 'bg-info';
                    icon = 'fa-info-circle';
                    break;
                default:
                    bgColor = 'bg-success';
                    icon = 'fa-check-circle';
            }
            
            const toastHtml = `
                <div id="${toastId}" class="toast" role="alert" data-bs-autohide="true" data-bs-delay="4000">
                    <div class="toast-header ${bgColor} text-white">
                        <i class="fas ${icon} me-2"></i>
                        <strong class="me-auto">SIGE Angola</strong>
                        <small>agora</small>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body">
                        ${message}
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, { delay: 4000, autohide: true });
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', function() {
                this.remove();
            });
        }
        
        // ============================================
        // FUNÇÕES DE MODAL DE CONFIRMAÇÃO
        // ============================================
        
        function showConfirmModal(title, message, details, onConfirm, type = 'warning') {
            pendingAction = onConfirm;
            
            document.getElementById('confirmTitle').innerText = title;
            document.getElementById('confirmMessage').innerHTML = message;
            
            const iconBox = document.getElementById('confirmIcon');
            const modalHeader = document.getElementById('confirmHeader');
            
            if (type === 'danger') {
                iconBox.className = 'icon-box danger';
                iconBox.innerHTML = '<i class="fas fa-trash-alt"></i>';
                modalHeader.className = 'modal-header bg-danger text-white';
            } else {
                iconBox.className = 'icon-box warning';
                iconBox.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                modalHeader.className = 'modal-header bg-warning text-dark';
            }
            
            const detailsDiv = document.getElementById('confirmDetails');
            if (details) {
                detailsDiv.innerHTML = details;
                detailsDiv.style.display = 'block';
            } else {
                detailsDiv.style.display = 'none';
            }
            
            const confirmModal = new bootstrap.Modal(document.getElementById('modalConfirmacao'));
            confirmModal.show();
        }
        
        document.getElementById('confirmOkBtn').addEventListener('click', function() {
            const confirmModal = bootstrap.Modal.getInstance(document.getElementById('modalConfirmacao'));
            confirmModal.hide();
            if (pendingAction) {
                pendingAction();
                pendingAction = null;
            }
        });
        
        document.getElementById('confirmCancelBtn').addEventListener('click', function() {
            pendingAction = null;
        });
        
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        function carregarPeriodo() {
            anoAtual = $('#selectAno').val();
            mesAtual = $('#selectMes').val();
            window.location.href = 'processar.php?ano=' + anoAtual + '&mes=' + mesAtual;
        }
        
        function formatMoney(value) {
            return parseFloat(value || 0).toLocaleString('pt-AO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Kz';
        }
        
        function carregarTotais() {
            $.ajax({
                url: 'processar.php',
                method: 'GET',
                data: { ajax: 'get_resumo_totais', processamento_id: processamentoId },
                dataType: 'json',
                success: function(data) {
                    if (data.success && data.totais) {
                        let t = data.totais;
                        $('#totalFuncionarios').text(t.total_funcionarios || 0);
                        $('#totalVencimentos').text(formatMoney((t.total_salario_base || 0) + (t.total_subsidios || 0)));
                        $('#totalDescontos').text(formatMoney((t.total_faltas || 0) + (t.total_inss || 0) + (t.total_irrf || 0)));
                        $('#totalLiquido').text(formatMoney(t.total_liquido || 0));
                        
                        $('#footTotalSalario').text(formatMoney(t.total_salario_base || 0));
                        $('#footTotalSubsidios').text(formatMoney(t.total_subsidios || 0));
                        $('#footTotalFaltas').text(formatMoney(t.total_faltas || 0));
                        $('#footTotalINSS').text(formatMoney(t.total_inss || 0));
                        $('#footTotalIRRF').text(formatMoney(t.total_irrf || 0));
                        $('#footTotalLiquido').text(formatMoney(t.total_liquido || 0));
                    }
                }
            });
        }
        
        function carregarFuncionarios() {
            $.ajax({
                url: 'processar.php',
                method: 'GET',
                data: { ajax: 'get_funcionarios_processamento', processamento_id: processamentoId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        let html = '';
                        
                        if (data.funcionarios.length === 0) {
                            $('#tbodyFuncionarios').html('<tr><td colspan="10" class="text-center text-muted">Nenhum funcionário adicionado                            $('#tfootFuncionarios').hide();
                            return;
                        }
                        
                        data.funcionarios.forEach(function(f) {
                            let salarioBase = f.salario_base || 0;
                            let subsidios = (f.subsidio_transporte || 0) + (f.subsidio_alimentacao || 0) + (f.outros_vencimentos || 0);
                            let faltasValor = f.faltas_valor || 0;
                            let inss = f.valor_inss || 0;
                            let irrf = f.valor_irrf || 0;
                            let liquido = f.salario_liquido || 0;
                            
                            let salarioClass = salarioBase === 0 ? 'salario-zero' : '';
                            
                            html += `<tr id="row_${f.funcionario_id}" class="funcionario-row-clickable" onclick="abrirModalConfigurarPorId(${f.funcionario_id}, '${f.nome.replace(/'/g, "\\'")}')">
                                <td>${f.numero_processo}</td>
                                <td>${f.nome}</td>
                                <td>${f.cargo}</td>
                                <td class="text-end ${salarioClass}" title="${salarioBase === 0 ? 'Clique para configurar salário' : ''}">
                                    ${formatMoney(salarioBase)}
                                    ${salarioBase === 0 ? '<i class="fas fa-exclamation-triangle text-warning"></i>' : ''}
                                </td>
                                <td class="text-end">${formatMoney(subsidios)}</td>
                                <td class="text-end text-danger">${formatMoney(faltasValor)}</td>
                                <td class="text-end">${formatMoney(inss)}</td>
                                <td class="text-end">${formatMoney(irrf)}</td>
                                <td class="text-end"><strong>${formatMoney(liquido)}</strong></td>
                                <td class="text-center" onclick="event.stopPropagation()">
                                    <button class="btn btn-sm btn-warning" onclick="abrirModalFaltas(${f.funcionario_id}, '${f.nome.replace(/'/g, "\\'")}')" title="Faltas">
                                        <i class="fas fa-calendar-times"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="removerFuncionario(${f.funcionario_id})" title="Remover">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>`;
                        });
                        
                        $('#tbodyFuncionarios').html(html);
                        $('#tfootFuncionarios').show();
                        carregarTotais();
                    }
                }
            });
        }
        
        function carregarFuncionariosDisponiveis() {
            $('#tbodyFuncionariosDisponiveis').html('<tr><td colspan="5" class="text-center"><div class="spinner-border text-primary" role="status"></div> Carregando...</td></tr>');
            
            $.ajax({
                url: 'processar.php',
                method: 'GET',
                data: { ajax: 'get_funcionarios_disponiveis', processamento_id: processamentoId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        funcionariosDisponiveis = data.funcionarios;
                        atualizarTabelaDisponiveis(funcionariosDisponiveis);
                    } else {
                        $('#tbodyFuncionariosDisponiveis').html('<tr><td colspan="5" class="text-center text-danger">Erro ao carregar funcionários</td></tr>');
                    }
                },
                error: function() {
                    $('#tbodyFuncionariosDisponiveis').html('<tr><td colspan="5" class="text-center text-danger">Erro ao carregar funcionários</td></tr>');
                }
            });
        }
        
        function atualizarTabelaDisponiveis(funcionarios) {
            let html = '';
            if (funcionarios.length === 0) {
                html = '<tr><td colspan="5" class="text-center text-muted">Nenhum funcionário disponível</td></tr>';
            } else {
                funcionarios.forEach(function(f) {
                    let salarioClass = (f.salario_base === 0) ? 'salario-zero' : '';
                    html += `<tr class="funcionario-row-clickable" onclick="abrirModalConfigurarPorIdDisponivel(${f.id}, '${f.nome.replace(/'/g, "\\'")}')">
                        <td class="text-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="funcionario-checkbox" value="${f.id}" data-nome="${f.nome}" data-salario="${f.salario_base}">
                        </td>
                        <td>${f.numero_processo}</td>
                        <td>${f.nome}</td>
                        <td>${f.cargo}</td>
                        <td class="text-end ${salarioClass}" title="${f.salario_base === 0 ? 'Clique para configurar salário' : ''}">
                            ${formatMoney(f.salario_base || 0)}
                            ${f.salario_base === 0 ? '<i class="fas fa-exclamation-triangle text-warning"></i>' : ''}
                        </td>
                    </tr>`;
                });
            }
            $('#tbodyFuncionariosDisponiveis').html(html);
            atualizarTotalSelecionados();
        }
        
        function abrirModalConfigurarPorId(funcionarioId, funcionarioNome) {
            $('#configFuncionarioId').val(funcionarioId);
            $('#configFuncionarioNome').text(funcionarioNome);
            
            let funcionario = null;
            if (funcionariosDisponiveis) {
                funcionario = funcionariosDisponiveis.find(f => f.id == funcionarioId);
            }
            
            if (funcionario) {
                $('#configSalarioBase').val(funcionario.salario_base || 0);
                $('#configSubsidioTransporte').val(funcionario.subsidio_transporte || 0);
                $('#configSubsidioAlimentacao').val(funcionario.subsidio_alimentacao || 0);
                $('#configOutrosVencimentos').val(funcionario.outros_vencimentos || 0);
            } else {
                $('#configSalarioBase').val(0);
                $('#configSubsidioTransporte').val(0);
                $('#configSubsidioAlimentacao').val(0);
                $('#configOutrosVencimentos').val(0);
            }
            
            $('#modalConfigurar').modal('show');
        }
        
        function abrirModalConfigurarPorIdDisponivel(funcionarioId, funcionarioNome) {
            abrirModalConfigurarPorId(funcionarioId, funcionarioNome);
        }
        
        // Função original salvarConfiguracao modificada
        window.salvarConfiguracao = function() {
            let funcionarioId = $('#configFuncionarioId').val();
            let funcionarioNome = $('#configFuncionarioNome').text();
            let salarioBase = $('#configSalarioBase').val();
            let subsidioTransporte = $('#configSubsidioTransporte').val();
            let subsidioAlimentacao = $('#configSubsidioAlimentacao').val();
            let outrosVencimentos = $('#configOutrosVencimentos').val();
            
            let details = `
                <strong>Funcionário:</strong> ${funcionarioNome}<br>
                <strong>Salário Base:</strong> ${formatMoney(salarioBase)}<br>
                <strong>Subsídio Transporte:</strong> ${formatMoney(subsidioTransporte)}<br>
                <strong>Subsídio Alimentação:</strong> ${formatMoney(subsidioAlimentacao)}<br>
                <strong>Outros Vencimentos:</strong> ${formatMoney(outrosVencimentos)}
            `;
            
            showConfirmModal(
                'Atualizar Configuração Salarial',
                `Tem certeza que deseja atualizar os dados salariais de <strong>${funcionarioNome}</strong>?`,
                details,
                function() {
                    showLoading();
                    $.ajax({
                        url: 'processar.php',
                        method: 'POST',
                        data: {
                            ajax: 'update_config',
                            funcionario_id: funcionarioId,
                            salario_base: salarioBase,
                            subsidio_transporte: subsidioTransporte,
                            subsidio_alimentacao: subsidioAlimentacao,
                            outros_vencimentos: outrosVencimentos
                        },
                        dataType: 'json',
                        success: function(data) {
                            hideLoading();
                            if (data.success) {
                                $('#modalConfigurar').modal('hide');
                                carregarFuncionarios();
                                carregarFuncionariosDisponiveis();
                                showToast(`✅ Configuração atualizada com sucesso!`, 'success');
                            } else {
                                showToast(`❌ ${data.message}`, 'danger');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showToast('❌ Erro ao salvar configuração', 'danger');
                        }
                    });
                },
                'warning'
            );
        };
        
        function filtrarFuncionarios() {
            let searchTerm = $('#searchFuncionario').val().toLowerCase();
            let filtrados = funcionariosDisponiveis.filter(f => 
                f.nome.toLowerCase().includes(searchTerm) || 
                f.numero_processo.toLowerCase().includes(searchTerm) ||
                f.cargo.toLowerCase().includes(searchTerm)
            );
            atualizarTabelaDisponiveis(filtrados);
        }
        
        function selecionarTodos() {
            let isChecked = $('#selectAllCheckbox').is(':checked');
            $('.funcionario-checkbox').prop('checked', isChecked);
            atualizarTotalSelecionados();
        }
        
        function atualizarTotalSelecionados() {
            let total = $('.funcionario-checkbox:checked').length;
            $('#totalSelecionados').text(total);
            let totalCheckboxes = $('.funcionario-checkbox').length;
            $('#selectAllCheckbox').prop('checked', total === totalCheckboxes && totalCheckboxes > 0);
        }
        
        $(document).on('change', '.funcionario-checkbox', function() {
            atualizarTotalSelecionados();
        });
        
        // Função original adicionarSelecionados modificada
        window.adicionarSelecionados = function() {
            let selecionados = [];
            let nomesSelecionados = [];
            $('.funcionario-checkbox:checked').each(function() {
                selecionados.push($(this).val());
                nomesSelecionados.push($(this).data('nome'));
            });
            
            if (selecionados.length === 0) {
                showToast('⚠️ Selecione pelo menos um funcionário!', 'warning');
                return;
            }
            
            let details = `<strong>Funcionários selecionados (${selecionados.length}):</strong><br>`;
            details += nomesSelecionados.map(n => `• ${n}`).join('<br>');
            
            showConfirmModal(
                'Adicionar Funcionários',
                `Tem certeza que deseja adicionar ${selecionados.length} funcionário(s) ao processamento?`,
                details,
                function() {
                    showLoading();
                    $.ajax({
                        url: 'processar.php',
                        method: 'POST',
                        data: {
                            ajax: 'add_multiplos_funcionarios',
                            processamento_id: processamentoId,
                            funcionarios_ids: JSON.stringify(selecionados)
                        },
                        dataType: 'json',
                        success: function(data) {
                            hideLoading();
                            if (data.success) {
                                $('#modalAdicionar').modal('hide');
                                carregarFuncionarios();
                                showToast(`✅ ${data.message || 'Funcionário(s) adicionado(s) com sucesso!'}`, 'success');
                                $('#selectAllCheckbox').prop('checked', false);
                            } else {
                                showToast(`❌ ${data.message}`, 'danger');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showToast('❌ Erro ao adicionar funcionários', 'danger');
                        }
                    });
                },
                'warning'
            );
        };
        
        function abrirModalAdicionar() {
            $('#searchFuncionario').val('');
            $('#selectAllCheckbox').prop('checked', false);
            carregarFuncionariosDisponiveis();
            $('#modalAdicionar').modal('show');
        }
        
        // Função original removerFuncionario modificada
        window.removerFuncionario = function(funcionarioId) {
            const row = $('#row_' + funcionarioId);
            const nomeFuncionario = row.find('td:eq(1)').text();
            const numeroProcesso = row.find('td:eq(0)').text();
            
            const details = `
                <strong>Funcionário:</strong> ${nomeFuncionario}<br>
                <strong>Nº Processo:</strong> ${numeroProcesso}<br>
                <strong>Esta ação irá remover o funcionário do processamento atual.</strong>
            `;
            
            showConfirmModal(
                'Remover Funcionário',
                `Tem certeza que deseja remover <strong>${nomeFuncionario}</strong> do processamento?`,
                details,
                function() {
                    showLoading();
                    $.ajax({
                        url: 'processar.php',
                        method: 'POST',
                        data: { ajax: 'remove_funcionario', processamento_id: processamentoId, funcionario_id: funcionarioId },
                        dataType: 'json',
                        success: function(data) {
                            hideLoading();
                            if (data.success) {
                                showToast('✅ Funcionário removido com sucesso!', 'success');
                                carregarFuncionarios();
                            } else {
                                showToast('❌ ' + data.message, 'danger');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showToast('❌ Erro ao remover funcionário', 'danger');
                        }
                    });
                },
                'danger'
            );
        };
        
        function calcularPreview() {
            let salarioBase = 0;
            let funcionarioId = $('#faltasFuncionarioId').val();
            
            $.ajax({
                url: 'processar.php',
                method: 'GET',
                data: { ajax: 'get_funcionario_salario', funcionario_id: funcionarioId },
                async: false,
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        salarioBase = data.salario_base || 0;
                    }
                }
            });
            
            let quantidadeDias = parseFloat($('#novaFaltaDias').val()) || 0;
            let valorPorDia = parseFloat($('#novaFaltaValorDia').val()) || 0;
            let percentual = parseFloat($('#novaFaltaPercentual').val()) || 100;
            let tipo = $('#novaFaltaTipo').val();
            
            let salarioDia = salarioBase / 22;
            let valorDesconto = 0;
            let calculoTexto = '';
            
            if (quantidadeDias > 0) {
                if (valorPorDia > 0) {
                    valorDesconto = valorPorDia * quantidadeDias;
                    calculoTexto = `${formatMoney(valorPorDia)} por dia × ${quantidadeDias} dias = ${formatMoney(valorDesconto)}`;
                } else {
                    valorDesconto = (salarioDia * percentual / 100) * quantidadeDias;
                    calculoTexto = `Salário dia: ${formatMoney(salarioDia)} × ${percentual}% × ${quantidadeDias} dias = ${formatMoney(valorDesconto)}`;
                }
                
                let tipoTexto = {
                    'justificada': 'Justificada (com desconto opcional)',
                    'injustificada': 'Injustificada (desconto total)',
                    'atestado': 'Atestado Médico (desconto conforme política)',
                    'licenca': 'Licença (desconto conforme política)'
                };
                
                $('#previewCalculo').html(`<strong>${tipoTexto[tipo]}:</strong> ${calculoTexto}`);
                $('#calculoPreview').show();
            } else {
                $('#calculoPreview').hide();
            }
        }
        
        $('#novaFaltaDias, #novaFaltaValorDia, #novaFaltaPercentual, #novaFaltaTipo').on('input change', function() {
            calcularPreview();
        });
        
        function abrirModalFaltas(funcionarioId, funcionarioNome) {
            $('#faltasFuncionarioId').val(funcionarioId);
            $('#faltasNomeFuncionario').text(funcionarioNome);
            $('#novaFaltaData').val(new Date().toISOString().split('T')[0]);
            $('#novaFaltaDias').val('');
            $('#novaFaltaValorDia').val('');
            $('#novaFaltaPercentual').val('100');
            $('#novaFaltaTipo').val('injustificada');
            $('#novaFaltaJustificativa').val('');
            $('#calculoPreview').hide();
            carregarFaltas(funcionarioId);
            $('#modalFaltas').modal('show');
        }
        
        function carregarFaltas(funcionarioId) {
            $.ajax({
                url: 'processar.php',
                method: 'GET',
                data: { ajax: 'get_faltas', funcionario_id: funcionarioId },
                dataType: 'json',
                success: function(data) {
                    let html = '';
                    let total = 0;
                    
                    if (data.faltas && data.faltas.length > 0) {
                        data.faltas.forEach(function(f) {
                            total += parseFloat(f.valor_desconto || 0);
                            let tipoClass = '';
                            let tipoText = '';
                            
                            switch(f.tipo_falta) {
                                case 'justificada':
                                    tipoClass = 'tipo-falta-justificada';
                                    tipoText = 'Justificada';
                                    break;
                                case 'injustificada':
                                    tipoClass = 'tipo-falta-injustificada';
                                    tipoText = 'Injustificada';
                                    break;
                                case 'atestado':
                                    tipoClass = 'tipo-falta-atestado';
                                    tipoText = 'Atestado Médico';
                                    break;
                                case 'licenca':
                                    tipoClass = 'tipo-falta-licenca';
                                    tipoText = 'Licença';
                                    break;
                                default:
                                    tipoClass = 'tipo-falta-injustificada';
                                    tipoText = 'Injustificada';
                            }
                            
                            html += `<tr>
                                <td>${f.data_falta}</td>
                                <td>${f.quantidade_dias}</td>
                                <td><span class="badge ${tipoClass}">${tipoText}</span></td>
                                <td class="text-danger">${formatMoney(f.valor_desconto)}</td>
                                <td>${f.justificativa || '-'}</td>
                                <td>
                                    <button class="btn btn-sm btn-danger" onclick="removerFalta(${f.id}, ${funcionarioId})">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>`;
                        });
                    } else {
                        html = '<tr><td colspan="6" class="text-center">Nenhuma falta registada</td></tr>';
                    }
                    
                    $('#tbodyFaltas').html(html);
                    $('#totalFaltasValor').text(formatMoney(total));
                }
            });
        }
        
        // Função original adicionarFalta modificada
        window.adicionarFalta = function() {
            let funcionarioId = $('#faltasFuncionarioId').val();
            let funcionarioNome = $('#faltasNomeFuncionario').text();
            let dataFalta = $('#novaFaltaData').val();
            let quantidadeDias = $('#novaFaltaDias').val();
            let tipoFalta = $('#novaFaltaTipo').val();
            let valorDescontoDia = $('#novaFaltaValorDia').val();
            let percentualDesconto = $('#novaFaltaPercentual').val();
            let justificativa = $('#novaFaltaJustificativa').val();
            
            if (!dataFalta || !quantidadeDias) {
                showToast('⚠️ Preencha a data e quantidade de dias!', 'warning');
                return;
            }
            
            let tipoTexto = {
                'justificada': 'Justificada',
                'injustificada': 'Injustificada',
                'atestado': 'Atestado Médico',
                'licenca': 'Licença'
            };
            
            let details = `
                <strong>Funcionário:</strong> ${funcionarioNome}<br>
                <strong>Data:</strong> ${dataFalta}<br>
                <strong>Dias:</strong> ${quantidadeDias}<br>
                <strong>Tipo:</strong> ${tipoTexto[tipoFalta]}<br>
                <strong>Justificativa:</strong> ${justificativa || 'N/A'}
            `;
            
            showConfirmModal(
                'Registrar Falta',
                `Tem certeza que deseja registrar esta falta para <strong>${funcionarioNome}</strong>?`,
                details,
                function() {
                    showLoading();
                    $.ajax({
                        url: 'processar.php',
                        method: 'POST',
                        data: {
                            ajax: 'add_falta',
                            funcionario_id: funcionarioId,
                            data_falta: dataFalta,
                            quantidade_dias: quantidadeDias,
                            tipo_falta: tipoFalta,
                            valor_desconto_dia: valorDescontoDia || 0,
                            percentual_desconto: percentualDesconto || 100,
                            justificativa: justificativa
                        },
                        dataType: 'json',
                        success: function(data) {
                            hideLoading();
                            if (data.success) {
                                $('#novaFaltaData').val('');
                                $('#novaFaltaDias').val('');
                                $('#novaFaltaValorDia').val('');
                                $('#novaFaltaPercentual').val('100');
                                $('#novaFaltaJustificativa').val('');
                                $('#calculoPreview').hide();
                                carregarFaltas(funcionarioId);
                                carregarFuncionarios();
                                showToast(`✅ Falta registada com sucesso! Desconto: ${formatMoney(data.valor_desconto)}`, 'success');
                            } else {
                                showToast(`❌ ${data.message}`, 'danger');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showToast('❌ Erro ao registrar falta', 'danger');
                        }
                    });
                },
                'warning'
            );
        };
        
        // Função original removerFalta modificada
        window.removerFalta = function(faltaId, funcionarioId) {
            const row = $('#tbodyFaltas').find(`button[onclick*="removerFalta(${faltaId}, ${funcionarioId})"]`).closest('tr');
            const dataFalta = row.find('td:eq(0)').text();
            const quantidadeDias = row.find('td:eq(1)').text();
            
            let details = `
                <strong>Data da Falta:</strong> ${dataFalta}<br>
                <strong>Dias:</strong> ${quantidadeDias}<br>
                <strong>Esta ação irá remover esta falta do registo.</strong>
            `;
            
            showConfirmModal(
                'Remover Falta',
                `Tem certeza que deseja remover esta falta?`,
                details,
                function() {
                    showLoading();
                    $.ajax({
                        url: 'processar.php',
                        method: 'POST',
                        data: { ajax: 'remove_falta', falta_id: faltaId, funcionario_id: funcionarioId },
                        dataType: 'json',
                        success: function(data) {
                            hideLoading();
                            if (data.success) {
                                carregarFaltas(funcionarioId);
                                carregarFuncionarios();
                                showToast(`✅ Falta removida com sucesso!`, 'success');
                            } else {
                                showToast(`❌ ${data.message}`, 'danger');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showToast('❌ Erro ao remover falta', 'danger');
                        }
                    });
                },
                'danger'
            );
        };
        
        // Função original calcularFolha modificada
        window.calcularFolha = function() {
            showConfirmModal(
                'Calcular Folha',
                `Tem certeza que deseja calcular a folha de pagamento?<br><br>Esta ação irá recalcular todos os vencimentos, descontos e salários líquidos.`,
                null,
                function() {
                    showLoading();
                    $.ajax({
                        url: 'processar.php',
                        method: 'POST',
                        data: { ajax: 'calcular', processamento_id: processamentoId },
                        dataType: 'json',
                        success: function(data) {
                            hideLoading();
                            if (data.success) {
                                carregarFuncionarios();
                                showToast(`✅ ${data.message}`, 'success');
                            } else {
                                showToast(`❌ ${data.message}`, 'danger');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showToast('❌ Erro ao calcular folha', 'danger');
                        }
                    });
                },
                'warning'
            );
        };
        
        function gerarHolerites() {
            window.open('gerar_holerites_lote.php?processamento_id=' + processamentoId, '_blank');
        }
        
        // Função original fecharProcessamento modificada
        window.fecharProcessamento = function() {
            showConfirmModal(
                'Fechar Processamento',
                `Tem certeza que deseja fechar este processamento?<br><br><strong>Atenção:</strong> Após fechado, não será possível alterar os dados.`,
                null,
                function() {
                    showLoading();
                    $.ajax({
                        url: 'processar.php',
                        method: 'POST',
                        data: { ajax: 'fechar', processamento_id: processamentoId },
                        dataType: 'json',
                        success: function(data) {
                            hideLoading();
                            if (data.success) {
                                showToast(`✅ ${data.message}`, 'success');
                                setTimeout(() => { location.reload(); }, 1500);
                            } else {
                                showToast(`❌ ${data.message}`, 'danger');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showToast('❌ Erro ao fechar processamento', 'danger');
                        }
                    });
                },
                'danger'
            );
        };
        
        $(document).ready(function() {
            carregarTotais();
        });
    </script>
</body>
</html>
<?php } ?>